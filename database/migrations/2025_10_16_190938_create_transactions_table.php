<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('type', [
                'earning',
                'mining', 
                'p2p',
                'transfer',
                'referral',
                'staking',
                'withdrawal',
                'deposit'
            ]);
            $table->decimal('amount', 16, 8)->default(0);
            $table->string('description')->nullable();
            $table->string('related_model')->nullable(); // e.g., 'P2PTrade', 'MiningSession'
            $table->unsignedBigInteger('related_id')->nullable(); // ID of related model
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['user_id', 'type', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['related_model', 'related_id']);
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}