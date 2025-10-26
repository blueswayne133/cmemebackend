<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Add is_active column if it doesn't exist
            if (!Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('email');
            }
            
            // Add status column if it doesn't exist
            if (!Schema::hasColumn('users', 'status')) {
                $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('is_active');
            }
            
            // Add other missing columns that your code expects
            if (!Schema::hasColumn('users', 'is_verified')) {
                $table->boolean('is_verified')->default(false)->after('status');
            }
            
            if (!Schema::hasColumn('users', 'kyc_status')) {
                $table->enum('kyc_status', ['pending', 'verified', 'rejected'])->nullable()->after('is_verified');
            }
            
            if (!Schema::hasColumn('users', 'kyc_verified_at')) {
                $table->timestamp('kyc_verified_at')->nullable()->after('kyc_status');
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'status', 'is_verified', 'kyc_status', 'kyc_verified_at']);
        });
    }
};