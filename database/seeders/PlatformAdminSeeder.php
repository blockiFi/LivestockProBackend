<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('PLATFORM_ADMIN_EMAIL', 'admin@farmcentral.com');
        $password = env('PLATFORM_ADMIN_PASSWORD', 'password');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'is_platform_admin' => true,
                'platform_admin_role' => 'super_admin',
            ]
        );

        $this->command->info("Platform admin seeded: {$email}");
    }
}
