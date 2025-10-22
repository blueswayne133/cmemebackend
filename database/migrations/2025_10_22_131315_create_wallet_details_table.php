<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('wallet_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('wallet_address');
            $table->string('network')->default('base');
            $table->boolean('is_connected')->default(false);
            $table->boolean('bonus_claimed')->default(false);
            $table->timestamp('connected_at')->nullable();

            // ✅ Add this line to fix the error
            $table->timestamp('last_updated_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            // Unique constraints
            $table->unique(['user_id', 'network']);
            $table->unique(['wallet_address', 'network']);

            // Indexes
            $table->index(['user_id', 'is_connected']);
            $table->index(['wallet_address']);
            $table->index(['network']);
            $table->index(['is_connected', 'bonus_claimed']);
            $table->index(['connected_at']);
            $table->index(['last_updated_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('wallet_details');
    }
};
