<?php
// database/migrations/2024_01_01_000003_create_p2p_disputes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('p2p_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('p2p_trades')->onDelete('cascade');
            $table->foreignId('raised_by')->constrained('users');
            $table->text('reason');
            $table->text('evidence')->nullable();
            $table->enum('status', ['open', 'in_review', 'resolved', 'cancelled']);
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('p2p_disputes');
    }
};