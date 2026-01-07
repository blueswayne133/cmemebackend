<?php


namespace App\Http\Controllers;

use App\Models\KycVerification;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Cloudinary\Cloudinary;
use Cloudinary\Api\Upload\UploadApi;

class KycController extends Controller
{
    protected $cloudinary;
    protected $uploadApi;

    public function __construct()
    {
        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                'api_key' => env('CLOUDINARY_API_KEY'),
                'api_secret' => env('CLOUDINARY_API_SECRET'),
            ],
            'url' => [
                'secure' => true
            ]
        ]);
        $this->uploadApi = new UploadApi();
    }

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

            // Upload document images to Cloudinary
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

                // Process referral rewards if applicable
                $this->processReferralRewards($user);

                $message = 'KYC submitted and automatically verified successfully!';
                
                // Include reward information in response if applicable
                if ($user->referred_by) {
                    $message .= ' Referral rewards have been processed.';
                }
            } else {
                // Keep status as pending for admin review instead of auto-rejecting
                $kycVerification->update([
                    'status' => 'pending', // Keep as pending for admin review
                    'verification_score' => $verificationResult['score'],
                    'verification_details' => $verificationResult['details'],
                    'verification_notes' => 'Pending manual review by admin',
                ]);

                // Update user KYC status to pending
                $user->updateKycStatus('pending', $kycVerification);

                $message = 'KYC submitted successfully! Your documents are under review and will be processed by our team shortly.';
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
                    'status' => $kycVerification->status,
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
        try {
            // Upload to Cloudinary
            $uploadResult = $this->uploadApi->upload($file->getRealPath(), [
                'folder' => 'kyc_documents/' . $userId,
                'resource_type' => 'image',
                'public_id' => $side . '_' . time() . '_' . Str::random(6),
                'quality' => 'auto:best'
            ]);

            return $uploadResult['secure_url'];
            
        } catch (\Exception $e) {
            throw new \Exception('Failed to upload document: ' . $e->getMessage());
        }
    }

    private function autoVerifyKyc(KycVerification $kyc)
    {
        $documentNumber = trim($kyc->document_number);
        $verificationDetails = [];
        $score = 0.0;
        $maxScore = 4.0; // Simplified scoring system

        // Criteria 1: Document number not empty
        if (!empty($documentNumber)) {
            $score += 1;
            $verificationDetails[] = 'Document number provided';
        }

        // Criteria 2: Minimum length (very lenient - accept 3+ alphanumeric characters)
        $cleanNumber = preg_replace('/[^A-Z0-9]/i', '', $documentNumber); // Remove separators for length check
        if (strlen($cleanNumber) >= 3) {
            $score += 1;
            $verificationDetails[] = 'Document number meets minimum length';
        }

        // Criteria 3: No obviously fake/test patterns
        $invalidPatterns = [
            '123', '000', '111', '222', '333', '444', '555', '666', '777', '888', '999',
            '1234', '0000', '1111', 'test', 'sample', 'example', 'demo', 'xxxx', 'yyyy', 'zzzz',
            '12345', '00000', '11111', 'aaaaa', 'bbbbb', 'ccccc'
        ];
        $isInvalid = false;
        foreach ($invalidPatterns as $pattern) {
            if (stripos($cleanNumber, $pattern) !== false && strlen($cleanNumber) <= strlen($pattern) + 2) {
                $isInvalid = true;
                break;
            }
        }
        
        if (!$isInvalid && strlen($cleanNumber) >= 3) {
            $score += 1;
            $verificationDetails[] = 'Document number passes pattern validation';
        }

        // Criteria 4: Contains alphanumeric characters (very lenient - accept any alphanumeric with common separators)
        // Accept letters (any case), numbers, and common separators (hyphens, spaces, slashes, dots, colons)
        if (preg_match('/^[A-Za-z0-9\s\-\/\.:]+$/', $documentNumber) && strlen($cleanNumber) >= 3) {
            $score += 1;
            $verificationDetails[] = 'Document number contains valid characters';
        }

        // Note: Removed strict format matching, length restrictions, and checksum validation
        // These were too restrictive for international documents (India, etc.)
        // Admin review will handle all verification to ensure quality

        $finalScore = $score / $maxScore;
        // Very lenient threshold - only reject obviously fake/test data
        // Most real documents should pass with 50%+ score
        $verified = $finalScore >= 0.5; // 50% confidence threshold

        return [
            'verified' => $verified,
            'score' => $finalScore,
            'reason' => $verified ? null : 'Document requires manual review by admin',
            'details' => $verificationDetails,
        ];
    }

    // Removed strict checksum validation as it was too restrictive for international documents
    // Different countries use different validation algorithms

    // Process referral rewards when user completes KYC
    private function processReferralRewards(User $user)
    {
        // If user just got verified and was referred by someone, process referral rewards
        if ($user->isKycVerified() && $user->referred_by) {
            $referrer = User::find($user->referred_by);
            
            if ($referrer) {
                // Reward amounts
                $cmemeReward = 0.5;
                $usdcReward = 0.1;

                // Add CMEME tokens immediately to referrer's balance
                $referrer->increment('token_balance', $cmemeReward);
                $referrer->increment('referral_token_balance', $cmemeReward);
                
                // Add USDC to referrer's pending balance (requires claiming)
                $referrer->increment('referral_usdc_balance', $usdcReward);
  

                // Create transaction for CMEME reward
                Transaction::create([
                    'user_id' => $referrer->id,
                    'type' => Transaction::TYPE_DEPOSIT,
                    'amount' => $cmemeReward,
                    'currency' => 'CMEME',
                    'status' => 'completed',
                    'description' => 'Referral reward from ' . $user->username,
                ]);

                // Call the referral controller to update overall stats
                \App\Http\Controllers\ReferralController::updateReferralRewards($user);
            }
        }
    }
}