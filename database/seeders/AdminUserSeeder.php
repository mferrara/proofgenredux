<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user (or update if exists)
        User::updateOrCreate(
            ['email' => 'ferrara1@gmail.com'],
            [
                'name' => 'Mike Ferrara',
                'password' => Hash::make('Arab13'), // Change this to a secure password!
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Admin user created/updated successfully!');
    }
}
