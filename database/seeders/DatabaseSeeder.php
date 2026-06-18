<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminEmail = (string) env('CDN_ADMIN_EMAIL', 'smoggrafton@gmail.com');
        $adminPassword = (string) env('CDN_ADMIN_PASSWORD', Str::password(32));

        User::updateOrCreate([
            'email' => $adminEmail,
        ], [
            'name' => 'smog-grafton',
            'password' => Hash::make($adminPassword),
            'email_verified_at' => now(),
        ]);
    }
}
