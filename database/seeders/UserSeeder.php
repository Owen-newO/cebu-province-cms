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
            ['email' => 'ginatilan@mata.cms'],
            [
                'name' => 'Ginatilan',
                'password' => Hash::make('ginatilanadmin'),
                'role' => 'ginatilan',
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
        User::updateOrCreate(
            ['email' => 'pilar@mata.cms'],
            [
                'name' => 'Pilar',
                'password' => Hash::make('pilaradmin'),
                'role' => 'pilar',
            ]
        );
        User::updateOrCreate(
            ['email' => 'tudela@mata.cms'],
            [
                'name' => 'Tudela',
                'password' => Hash::make('tudelaadmin'),
                'role' => 'tudela',
            ]
        );
        User::updateOrCreate(
            ['email' => 'sanfrancisco@mata.cms'],
            [
                'name' => 'SanFrancisco',
                'password' => Hash::make('sanfranciscoadmin'),
                'role' => 'sanfrancisco',
            ]
        );
        User::updateOrCreate(
            ['email' => 'poro@mata.cms'],
            [
                'name' => 'Poro',
                'password' => Hash::make('poroadmin'),
                'role' => 'poro',
            ]
        );
        User::updateOrCreate(
            ['email' => 'danao@mata.cms'],
            [
                'name' => 'Danao',
                'password' => Hash::make('danaoadmin'),
                'role' => 'danao',
            ]
        );
        User::updateOrCreate(
            ['email' => 'balamban@mata.cms'],
            [
                'name' => 'Balamban',
                'password' => Hash::make('balambanadmin'),
                'role' => 'balamban',
            ]
        );
        User::updateOrCreate(
            ['email' => 'daanbantayan@mata.cms'],
            [
                'name' => 'Daanbantayan',
                'password' => Hash::make('daanbantayanadmin'),
                'role' => 'daanbantayan',
            ]
        );
        User::updateOrCreate(
            ['email' => 'medellin@mata.cms'],
            [
                'name' => 'Medellin',
                'password' => Hash::make('medellinadmin'),
                'role' => 'medellin',
            ]
        );
        User::updateOrCreate(
            ['email' => 'bantayan@mata.cms'],
            [
                'name' => 'Bantayan',
                'password' => Hash::make('bantayanadmin'),
                'role' => 'bantayan',
            ]
        );
        User::updateOrCreate(
            ['email' => 'santafe@mata.cms'],
            [
                'name' => 'Santafe',
                'password' => Hash::make('santafeadmin'),
                'role' => 'santafe',
            ]
        );
        User::updateOrCreate(
            ['email' => 'madridejos@mata.cms'],
            [
                'name' => 'Madridejos',
                'password' => Hash::make('madridejosadmin'),
                'role' => 'madridejos',
            ]
        );

    }
}
