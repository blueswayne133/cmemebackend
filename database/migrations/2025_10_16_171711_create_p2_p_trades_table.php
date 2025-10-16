<?php
// database/migrations/2024_01_01_000001_create_p2p_trades_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('p2p_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users');
            $table->foreignId('buyer_id')->nullable()->constrained('users');
            $table->enum('type', ['buy', 'sell']);
            $table->decimal('amount', 18, 8);
            $table->decimal('price', 18, 8);
            $table->decimal('total', 18, 8);
            $table->enum('payment_method', ['bank_transfer', 'wise', 'paypal', 'revolut', 'other']);
            $table->text('payment_details')->nullable();
            $table->enum('status', ['active', 'processing', 'completed', 'cancelled', 'disputed']);
            $table->text('terms')->nullable();
            $table->integer('time_limit')->default(15);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            
            $table->index(['status', 'type']);
            $table->index(['seller_id', 'status']);
            $table->index(['buyer_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('p2p_trades');
    }
};