<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: roles before users, users before anything that stamps `created_by`.
     * The roles and the first sign-in run everywhere. Demo shops and products (invented) will
     * seed on a developer's machine only (APP_ENV=local), as GiftNest's demo event does.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            UsersTableSeeder::class,
            PlatformSettingsSeeder::class,
            CatalogueSeeder::class,
        ]);
    }
}
