<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'samboan@mata.cms'],
            [
                'name' => 'Samboan',
                'password' => Hash::make('samboanadmin'),
                'role' => 'samboan',
            ]
        );

        User::updateOrCreate(
            ['email' => 'oslob@mata.cms'],
            [
                'name' => 'Oslob',
                'password' => Hash::make('oslobadmin'),
                'role' => 'oslob',
            ]
        );

        User::updateOrCreate(
            ['email' => 'tuburan@mata.cms'],
            [
                'name' => 'Tuburan',
                'password' => Hash::make('tuburanadmin'),
                'role' => 'tuburan',
            ]
        );
        User::updateOrCreate(
            ['email' => 'moalboal@mata.cms'],
            [
                'name' => 'Moalboal',
                'password' => Hash::make('moalboaladmin'),
                'role' => 'moalboal',
            ]
        );
        User::updateOrCreate(
            ['email' => 'aloguinsan@mata.cms'],
            [
                'name' => 'Aloguinsan',
                'password' => Hash::make('aloguinsanadmin'),
                'role' => 'aloguinsan',
            ]
        );
         User::updateOrCreate(
            ['email' => 'alegria@mata.cms'],
            [
                'name' => 'Alegria',
                'password' => Hash::make('alegriaadmin'),
                'role' => 'alegria',
            ]
        );
    }
}
