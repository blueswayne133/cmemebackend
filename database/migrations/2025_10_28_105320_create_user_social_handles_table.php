<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_social_handles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('platform', ['twitter', 'telegram']);
            $table->string('handle');
            $table->timestamp('connected_at');
            $table->timestamps();

            // Ensure one handle per platform per user
            $table->unique(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_handles');
    }
};