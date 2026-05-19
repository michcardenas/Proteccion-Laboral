<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InitialUsersSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'Password123!';

    public function run(): void
    {
        $users = [
            [
                'name' => 'Carlos Guillermo Camacho Victoria',
                'email' => 'carlos.camacho@proteccionlaboral.co',
                'role' => 'director',
            ],
            [
                'name' => 'María Camila Ocampo Gómez',
                'email' => 'camila.ocampo@proteccionlaboral.co',
                'role' => 'coordinador',
            ],
            [
                'name' => 'Leidy Dahiana Rodríguez Muñoz',
                'email' => 'leidy.rodriguez@proteccionlaboral.co',
                'role' => 'abogado_interno',
            ],
            [
                'name' => 'María Carolina Ramos Sepúlveda',
                'email' => 'carolina.ramos@proteccionlaboral.co',
                'role' => 'abogado_externo',
            ],
            [
                'name' => 'Eliana',
                'email' => 'eliana@proteccionlaboral.co',
                'role' => 'contador',
            ],
        ];

        foreach ($users as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make(self::DEFAULT_PASSWORD),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$data['role']]);
        }
    }
}
