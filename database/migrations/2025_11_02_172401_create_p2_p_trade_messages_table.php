<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('p2p_trade_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('p2p_trades')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('message');
            $table->enum('type', ['system', 'user'])->default('user');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            
            $table->index(['trade_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('p2p_trade_messages');
    }
};