<?php
// database/migrations/2025_10_20_112701_create_tasks_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->decimal('reward_amount', 10, 4)->default(0);
            $table->enum('reward_type', ['CMEME', 'USDC'])->default('CMEME');
            $table->string('type')->unique(); // watch_ads, daily_streak, connect_twitter, connect_telegram
            $table->integer('max_attempts_per_day')->default(1);
            $table->integer('cooldown_minutes')->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_available']);
            $table->index('type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tasks');
    }
};