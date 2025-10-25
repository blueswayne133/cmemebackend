<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
             $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('uid')->unique();
            $table->string('wallet_address')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('referral_code')->unique();
            $table->foreignId('referred_by')->nullable()->constrained('users');
            $table->decimal('token_balance', 15, 8)->default(0);
            $table->decimal('usdc_balance', 15, 8)->default(0);
            $table->decimal('referral_usdc_balance', 10, 2)->default(0);
            $table->decimal('referral_token_balance', 10, 2)->default(0);
            $table->boolean('can_claim_referral_usdc')->default(false);
            $table->integer('mining_streak')->default(0);
            $table->timestamp('last_mining_at')->nullable();
            $table->enum('kyc_status', ['not_submitted', 'pending', 'verified', 'rejected'])->default('not_submitted');
            $table->unsignedBigInteger('current_kyc_id')->nullable(); // Remove foreign key constraint here
            $table->timestamp('kyc_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
