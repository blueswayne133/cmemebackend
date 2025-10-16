<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class P2PTradeProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'trade_id',
        'uploaded_by',
        'proof_type',
        'file_path',
        'description',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(P2PTrade::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getProofTypeLabel(): string
    {
        return match($this->proof_type) {
            'payment_proof' => 'Payment Proof',
            'receipt_proof' => 'Receipt Proof',
            'identity_proof' => 'Identity Proof',
            default => ucfirst(str_replace('_', ' ', $this->proof_type))
        };
    }
}