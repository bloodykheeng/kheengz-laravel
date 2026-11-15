<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// php artisan db:seed --class=RolesAndPermissionsSeeder
// php artisan permission:cache-reset

/**
 * The account roles and every permission the API gates on (workspace D26).
 *
 *   Admin   Kheengz itself, or the owner of a single vendor shop: everything.
 *   Staff   Helps run the platform (marketplace) or the shop (single vendor): products, stock,
 *           orders and counter sales arrive with Phase 1; never settings, payouts or staff.
 *   Vendor  Runs a shop on the marketplace. What they may do in ONE shop is their
 *           `vendor_users` role (Owner / Staff), decided per shop, never by this account role.
 *
 * Buyers hold no role: they never sign in. routes/api.php maps each permission to its routes, so
 * one added here without a `can:` protects nothing. Staff and Vendor have no permissions until
 * their modules land; each module adds its own here.
 *
 * Idempotent and authoritative: a role or permission no longer listed here is deleted on the
 * next run.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $staff = [];

        $vendor = [];

        // Everything, including the setup only the owner of the installation may touch.
        $admin = [
            ...$staff,
            ...$vendor,
            'view users',
            'manage users',
            'manage roles',
            'view activity logs',
            'view queue',
            'manage queue',
            'manage settings',
        ];

        $rolesWithPermissions = [
            'Admin' => $admin,
            'Staff' => $staff,
            'Vendor' => $vendor,
        ];

        $definedRoleNames = array_keys($rolesWithPermissions);
        $definedPermissionNames = collect($rolesWithPermissions)->flatten()->unique()->values()->all();

        Role::whereNotIn('name', $definedRoleNames)->each(function ($role) {
            $this->command?->warn("Deleting role: {$role->name}");
            $role->delete();
        });

        foreach ($rolesWithPermissions as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName]);

            $role->syncPermissions(collect($permissions)->unique()->map(
                fn (string $name) => Permission::firstOrCreate(['name' => $name])
            ));

            $this->command?->info("Synced role: {$roleName} with ".count($permissions).' permissions');
        }

        Permission::whereNotIn('name', $definedPermissionNames)->each(function ($permission) {
            $this->command?->warn("Deleting permission: {$permission->name}");
            $permission->delete();
        });
    }
}
