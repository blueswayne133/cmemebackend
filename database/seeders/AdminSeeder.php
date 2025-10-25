<?php
// database/seeders/AdminSeeder.php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@cmeme.com',
            'password' => Hash::make('admin123'),
            'is_super_admin' => true,
            'permissions' => ['*']
        ]);

        // You can add more admin users here
        Admin::create([
            'name' => 'Support Admin',
            'email' => 'support@cmeme.com',
            'password' => Hash::make('support123'),
            'is_super_admin' => false,
            'permissions' => [
                'view_users',
                'view_kyc',
                'verify_kyc'
            ]
        ]);
    }
}