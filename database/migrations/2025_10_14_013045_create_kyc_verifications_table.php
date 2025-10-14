<?php
// database/migrations/2024_01_01_000000_create_kyc_verifications_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('document_type', ['passport', 'drivers_license', 'national_id']);
            $table->string('document_number', 50);
            $table->string('document_front_path');
            $table->string('document_back_path');
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->text('verification_notes')->nullable();
            $table->decimal('verification_score', 5, 2)->nullable()->comment('Auto-verification confidence score 0-1');
            $table->json('verification_details')->nullable()->comment('Detailed verification results');
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index('document_number');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('kyc_verifications');
    }
};