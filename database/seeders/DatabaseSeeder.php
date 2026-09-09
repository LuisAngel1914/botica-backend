<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the first administrator only when deployment credentials exist.
     * This keeps local and production passwords out of source control.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            $this->command?->warn('Bootstrap administrator skipped: ADMIN_EMAIL or ADMIN_PASSWORD is missing.');

            return;
        }

        $attributes = [
            'name' => env('ADMIN_NAME', 'Administrador'),
            'email' => $email,
            'password' => Hash::make($password),
        ];

        if (Schema::hasColumn('users', 'role')) {
            $attributes['role'] = 'admin';
        }

        if (Schema::hasColumn('users', 'activo')) {
            $attributes['activo'] = true;
        }

        User::updateOrCreate(['email' => $email], $attributes);
    }
}
