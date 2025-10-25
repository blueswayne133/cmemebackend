<?php
// app/Http/Controllers/KycController.php

namespace App\Http\Controllers;

use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class KycController extends Controller
{
    public function submitKyc(Request $request)
    {
        $user = $request->user();

        // Check if user can submit KYC
        if (!$user->canSubmitKyc()) {
            return response()->json([
                'status' => 'error',
                'message' => 'You already have a KYC submission in progress'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'document_type' => 'required|in:passport,drivers_license,national_id',
            'document_number' => 'required|string|max:50',
            'document_front' => 'required|image|mimes:jpeg,png,jpg|max:5120',
            'document_back' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Upload document images
            $frontPath = $this->uploadDocument($request->file('document_front'), $user->id, 'front');
            $backPath = $this->uploadDocument($request->file('document_back'), $user->id, 'back');

            // Create KYC verification record
            $kycVerification = KycVerification::create([
                'user_id' => $user->id,
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'document_front_path' => $frontPath,
                'document_back_path' => $backPath,
                'status' => 'pending',
                'submitted_at' => now(),
            ]);

            // Auto-verify the KYC
            $verificationResult = $this->autoVerifyKyc($kycVerification);

            if ($verificationResult['verified']) {
                $kycVerification->update([
                    'status' => 'verified',
                    'verified_at' => now(),
                    'verification_score' => $verificationResult['score'],
                    'verification_details' => $verificationResult['details'],
                    'verification_notes' => 'Automatically verified by system',
                ]);

                // Update user KYC status
                $user->updateKycStatus('verified', $kycVerification);

                // Process referral rewards if applicable - AUTOMATICALLY ADD REWARDS
                $this->processReferralRewards($user);

                $message = 'KYC submitted and automatically verified successfully!';
                
                // Include reward information in response if applicable
                if ($user->referred_by) {
                    $message .= ' Referral rewards have been automatically added to your referrer.';
                }
            } else {
                $kycVerification->update([
                    'status' => 'rejected',
                    'rejection_reason' => $verificationResult['reason'],
                    'verification_score' => $verificationResult['score'],
                    'verification_details' => $verificationResult['details'],
                ]);

                // Update user KYC status
                $user->updateKycStatus('rejected', $kycVerification);

                $message = 'KYC submitted but could not be verified automatically. Please contact support.';
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => [
                    'kyc_status' => $user->kyc_status,
                    'is_verified' => $user->is_verified,
                    'verification_score' => $kycVerification->verification_score,
                    'rejection_reason' => $kycVerification->rejection_reason,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit KYC: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getKycStatus(Request $request)
    {
        $user = $request->user()->load('currentKyc');

        $responseData = [
            'kyc_status' => $user->kyc_status,
            'is_verified' => $user->is_verified,
            'kyc_verified_at' => $user->kyc_verified_at,
        ];

        // Include current KYC details if exists
        if ($user->currentKyc) {
            $responseData['current_kyc'] = [
                'document_type' => $user->currentKyc->document_type,
                'document_type_label' => $user->currentKyc->getDocumentTypeLabel(),
                'document_number' => $user->currentKyc->document_number,
                'status' => $user->currentKyc->status,
                'submitted_at' => $user->currentKyc->submitted_at,
                'verified_at' => $user->currentKyc->verified_at,
                'rejection_reason' => $user->currentKyc->rejection_reason,
                'verification_score' => $user->currentKyc->verification_score,
                'verification_notes' => $user->currentKyc->verification_notes,
            ];
        }

        // Include KYC history
        $responseData['kyc_history'] = $user->kycVerifications()
            ->latest()
            ->get()
            ->map(function ($kyc) {
                return [
                    'id' => $kyc->id,
                    'document_type' => $kyc->document_type,
                    'document_type_label' => $kyc->getDocumentTypeLabel(),
                    'status' => $kyc->status,
                    'submitted_at' => $kyc->submitted_at,
                    'verified_at' => $kyc->verified_at,
                    'rejection_reason' => $kyc->rejection_reason,
                    'verification_score' => $kyc->verification_score,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $responseData
        ]);
    }

    public function getKycHistory(Request $request)
    {
        $user = $request->user();

        $kycHistory = $user->kycVerifications()
            ->latest()
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => [
                'kyc_history' => $kycHistory
            ]
        ]);
    }

    private function uploadDocument($file, $userId, $side)
    {
        $filename = 'kyc/' . $userId . '/' . $side . '_' . time() . '_' . Str::random(6) . '.' . $file->getClientOriginalExtension();
        
        $path = $file->storeAs('public', $filename);
        
        return $filename;
    }

    private function autoVerifyKyc(KycVerification $kyc)
    {
        $documentNumber = $kyc->document_number;
        $verificationDetails = [];
        $score = 0.0;
        $maxScore = 6.0;

        // Criteria 1: Document number not empty
        if (!empty($documentNumber)) {
            $score += 1;
            $verificationDetails[] = 'Document number provided';
        }

        // Criteria 2: Minimum length
        if (strlen($documentNumber) >= 5) {
            $score += 1;
            $verificationDetails[] = 'Document number meets minimum length';
        }

        // Criteria 3: No invalid patterns
        $invalidPatterns = ['123456', '000000', '111111', 'test', 'sample'];
        if (!in_array(strtolower($documentNumber), $invalidPatterns)) {
            $score += 1;
            $verificationDetails[] = 'Document number passes pattern validation';
        }

        // Criteria 4: Format matches document type
        $formatValid = false;
        switch ($kyc->document_type) {
            case 'passport':
                $formatValid = preg_match('/^[A-Z0-9]{6,9}$/', $documentNumber);
                break;
            case 'drivers_license':
                $formatValid = preg_match('/^[A-Z0-9]{5,15}$/', $documentNumber);
                break;
            case 'national_id':
                $formatValid = preg_match('/^[0-9]{8,12}$/', $documentNumber);
                break;
        }
        
        if ($formatValid) {
            $score += 1;
            $verificationDetails[] = 'Document number format matches document type';
        }

        // Criteria 5: Only allowed characters
        if (preg_match('/^[A-Z0-9]+$/', $documentNumber)) {
            $score += 1;
            $verificationDetails[] = 'Document number contains valid characters';
        }

        // Criteria 6: Checksum validation (if applicable)
        $checksumValid = $this->validateDocumentChecksum($documentNumber, $kyc->document_type);
        if ($checksumValid) {
            $score += 1;
            $verificationDetails[] = 'Document number passes checksum validation';
        }

        $finalScore = $score / $maxScore;
        $verified = $finalScore >= 0.7; // 70% confidence threshold

        return [
            'verified' => $verified,
            'score' => $finalScore,
            'reason' => $verified ? null : 'Document could not be automatically verified with sufficient confidence',
            'details' => $verificationDetails,
        ];
    }

    private function validateDocumentChecksum($documentNumber, $documentType)
    {
        if ($documentType === 'national_id' && is_numeric($documentNumber)) {
            return $this->luhnCheck($documentNumber);
        }

        return true;
    }

    private function luhnCheck($number)
    {
        $number = strrev(preg_replace('/[^\d]/', '', $number));
        $sum = 0;

        for ($i = 0, $j = strlen($number); $i < $j; $i++) {
            $digit = (int) $number[$i];

            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    // Add this method to process referral rewards
    private function processReferralRewards(User $user)
    {
        // If user just got verified and was referred by someone, process referral rewards
        if ($user->isKycVerified() && $user->referred_by) {
            \App\Http\Controllers\ReferralController::updateReferralRewards($user);
        }
    }
}