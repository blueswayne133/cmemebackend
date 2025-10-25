<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('user_security_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('email_2fa_enabled')->default(false);
            $table->boolean('sms_2fa_enabled')->default(false);
            $table->boolean('authenticator_2fa_enabled')->default(false);
            $table->boolean('login_alerts')->default(true);
            $table->boolean('new_device_alerts')->default(true);
            $table->timestamp('last_password_change')->nullable();
            $table->json('trusted_devices')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_security_settings');
    }
};