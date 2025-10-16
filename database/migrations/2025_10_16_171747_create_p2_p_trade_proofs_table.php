<?php
// database/migrations/2024_01_01_000002_create_p2p_trade_proofs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('p2p_trade_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('p2p_trades')->onDelete('cascade');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('proof_type');
            $table->string('file_path');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('p2p_trade_proofs');
    }
};