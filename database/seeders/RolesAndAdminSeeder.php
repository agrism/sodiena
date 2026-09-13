<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolesAndAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => Role::ADMIN],
            [
                'name' => 'Administrators',
                'description' => 'Pilna sistēmas un notikumu pārvaldība, avotu administrēšana un lietotāju tiesības.',
            ]
        );

        $regularRole = Role::firstOrCreate(
            ['slug' => Role::REGULAR],
            [
                'name' => 'Lietotājs',
                'description' => 'Reģistrēts lietotājs ar standarta tiesībām.',
            ]
        );

        // Create or update default administrator
        $admin = User::firstOrCreate(
            ['email' => 'admin@sodiena.lv'],
            [
                'name' => 'Administrators',
                'password' => Hash::make('Admin123!'),
                'email_verified_at' => now(),
            ]
        );

        $admin->assignRole($adminRole);
    }
}
