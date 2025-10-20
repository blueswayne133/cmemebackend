<?php
// database/migrations/2025_10_20_112836_create_user_task_progress_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('user_task_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('task_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('task_type')->nullable(); // For default tasks without DB entries
            $table->integer('attempts_count')->default(0);
            $table->date('completion_date')->nullable(); // Store date separately for unique constraint
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamps();

            // Unique constraints using completion_date
            $table->unique(['user_id', 'task_id', 'completion_date']);
            $table->unique(['user_id', 'task_type', 'completion_date']);
            
            // Indexes for better performance
            $table->index(['user_id', 'completion_date']);
            $table->index(['task_id', 'completion_date']);
            $table->index(['task_type', 'completion_date']);
            $table->index(['last_completed_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_task_progress');
    }
};