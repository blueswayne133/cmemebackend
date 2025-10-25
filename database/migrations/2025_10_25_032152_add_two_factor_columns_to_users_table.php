<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->boolean('phone_verified')->default(false)->after('phone');
            $table->boolean('two_factor_enabled')->default(false)->after('phone_verified');
            $table->string('two_factor_type')->nullable()->after('two_factor_enabled'); // 'authenticator', 'email', 'sms'
            $table->text('two_factor_secret')->nullable()->after('two_factor_type');
            $table->text('backup_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_enabled_at')->nullable()->after('backup_codes');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'phone_verified',
                'two_factor_enabled',
                'two_factor_type',
                'two_factor_secret',
                'backup_codes',
                'two_factor_enabled_at'
            ]);
        });
    }
};