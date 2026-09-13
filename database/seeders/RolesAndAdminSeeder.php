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
        $admin = User::where('email', '7924@inbox.lv')->first()
            ?: User::where('email', 'admin@sodiena.lv')->first()
            ?: new User();

        $admin->name = 'Administrators';
        $admin->email = '7924@inbox.lv';
        $admin->password = Hash::make('lfc12');
        $admin->email_verified_at = now();
        $admin->save();

        $admin->assignRole($adminRole);
    }
}
