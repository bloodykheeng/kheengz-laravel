<?php

namespace Database\Seeders;

// php artisan db:seed --class=UsersTableSeeder

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The accounts a fresh database can be signed in with, all on PASSWORD.
 *
 *   admin@kheengz.com     Admin
 *
 * Demo vendors (invented people) will be created with their shops, on a developer's
 * machine only.
 *
 * `firstOrCreate`, so a re-seed never overwrites a password somebody changed.
 * Change these passwords before going live.
 */
class UsersTableSeeder extends Seeder
{
    public const PASSWORD = 'Kheengz@2026';

    /**
     * One seeded sign-in, created once and never overwritten.
     *
     * @param  array{name: string, email: string, phone: string, role: string}  $data
     */
    public static function ensure(array $data): User
    {
        $user = User::firstOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email_verified_at' => now(),
                'password' => Hash::make(self::PASSWORD),
                'status' => 'Active',
            ],
        );

        // Seeded accounts are confirmed: email_verified_at is not mass-assignable.
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if (! $user->hasRole($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return $user;
    }

    public function run(): void
    {
        $users = [
            ['name' => 'Kheengz Admin', 'email' => 'admin@kheengz.com', 'phone' => '256700000001', 'role' => 'Admin'],
        ];

        foreach ($users as $data) {
            self::ensure($data);
        }

        $this->command?->table(
            ['Sign in as', 'Role', 'Password'],
            collect($users)->map(fn ($u) => [$u['email'], $u['role'], self::PASSWORD])->all(),
        );
    }
}
