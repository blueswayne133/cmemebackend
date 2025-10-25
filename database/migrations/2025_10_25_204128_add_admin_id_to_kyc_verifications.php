<?php
// database/migrations/2024_01_01_add_admin_id_to_kyc_verifications.php

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
        Schema::table('kyc_verifications', function (Blueprint $table) {
            // Check if admin_id column doesn't exist before adding
            if (!Schema::hasColumn('kyc_verifications', 'admin_id')) {
                $table->foreignId('admin_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('admins')
                    ->onDelete('set null');
            }

            // Check if rejection_reason column doesn't exist before adding
            if (!Schema::hasColumn('kyc_verifications', 'rejection_reason')) {
                $table->text('rejection_reason')
                    ->nullable()
                    ->after('verification_notes');
            }

            // Add verified_by_admin_at timestamp
            if (!Schema::hasColumn('kyc_verifications', 'verified_by_admin_at')) {
                $table->timestamp('verified_by_admin_at')
                    ->nullable()
                    ->after('verified_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['admin_id']);
            
            // Then drop columns
            $table->dropColumn(['admin_id', 'rejection_reason', 'verified_by_admin_at']);
        });
    }
};