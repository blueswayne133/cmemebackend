<?php
// database/migrations/2024_01_01_000000_create_settings_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50)->default('wallet')->index();
            $table->string('key', 100)->index();
            $table->text('value')->nullable();
            $table->enum('type', ['string', 'integer', 'double', 'boolean'])->default('string');
            $table->timestamps();

            // Unique constraint to prevent duplicate settings
            $table->unique(['category', 'key']);
        });

        // Insert default wallet settings
        $this->insertDefaultWalletSettings();
    }

    /**
     * Insert default wallet settings
     */
    private function insertDefaultWalletSettings()
    {
        $defaultSettings = [
            [
                'category' => 'wallet',
                'key' => 'deposit_address',
                'value' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
                'type' => 'string',
            ],
            [
                'category' => 'wallet',
                'key' => 'network',
                'value' => 'base',
                'type' => 'string',
            ],
            [
                'category' => 'wallet',
                'key' => 'token',
                'value' => 'USDC',
                'type' => 'string',
            ],
            [
                'category' => 'wallet',
                'key' => 'min_deposit',
                'value' => '10',
                'type' => 'double',
            ],
        ];

        DB::table('settings')->insert($defaultSettings);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('settings');
    }
}