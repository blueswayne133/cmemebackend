<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class TaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tasks = [
            [
                'title' => 'Watch Ads',
                'description' => 'Watch ads to earn CMEME tokens. Up to 60 times daily.',
                'reward_amount' => 0.05,
                'reward_type' => 'CMEME',
                'type' => 'watch_ads',
                'max_attempts_per_day' => 60,
                'cooldown_minutes' => 0,
                'sort_order' => 1,
                'is_active' => true,
                'is_available' => true,
                'metadata' => ['category' => 'daily']
            ],
            [
                'title' => 'Daily Streak Claim',
                'description' => 'Claim your daily streak bonus.',
                'reward_amount' => 0.5,
                'reward_type' => 'CMEME',
                'type' => 'daily_streak',
                'max_attempts_per_day' => 1,
                'cooldown_minutes' => 1440,
                'sort_order' => 2,
                'is_active' => true,
                'is_available' => true,
                'metadata' => ['category' => 'daily']
            ],
            [
                'title' => 'Connect X (Twitter) Account',
                'description' => 'Connect your X (Twitter) account to earn rewards.',
                'reward_amount' => 5,
                'reward_type' => 'CMEME',
                'type' => 'connect_twitter',
                'max_attempts_per_day' => 1,
                'cooldown_minutes' => 0,
                'sort_order' => 3,
                'is_active' => true,
                'is_available' => true,
                'metadata' => ['category' => 'social']
            ],
            [
                'title' => 'Connect Telegram Account',
                'description' => 'Connect your Telegram account to earn rewards.',
                'reward_amount' => 5,
                'reward_type' => 'CMEME',
                'type' => 'connect_telegram',
                'max_attempts_per_day' => 1,
                'cooldown_minutes' => 0,
                'sort_order' => 4,
                'is_active' => true,
                'is_available' => true,
                'metadata' => ['category' => 'social']
            ],
            [
                'title' => 'Connect Wallet',
                'description' => 'Connect your Base Network wallet to earn bonus tokens.',
                'reward_amount' => 0.5,
                'reward_type' => 'CMEME',
                'type' => 'connect_wallet',
                'max_attempts_per_day' => 1,
                'cooldown_minutes' => 0,
                'sort_order' => 5,
                'is_active' => true,
                'is_available' => true,
                'metadata' => ['category' => 'wallet']
            ]
        ];

        foreach ($tasks as $task) {
            Task::create($task);
        }
    }
}