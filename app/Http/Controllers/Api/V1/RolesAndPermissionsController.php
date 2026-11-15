<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

#[Group('Roles & Permissions', 'Manage roles and their permissions.', weight: 5)]
class RolesAndPermissionsController extends Controller
{
    use LoggableTrait;

    /**
     * List all roles with their assigned permissions.
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        return response()->json($roles);
    }

    /**
     * The role vocabulary: every role, as an id and a name. For pickers that
     * address a role rather than grant it.
     */
    public function names(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Role::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Role $role) => ['id' => (string) $role->id, 'name' => $role->name])
                ->values(),
        ]);
    }

    /**
     * Get the roles the authenticated user is allowed to assign.
     *
     * Returns a filtered list based on the caller's own role.
     */
    public function assignable(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('roles');

        // One role per account (UserController assigns exactly one).
        $callerRole = $user->getRoleNames()->first();
        $map = $this->assignableRoleMap();
        $assignable = $map[$callerRole] ?? [];

        return response()->json(array_values($assignable));
    }

    /**
     * Get all roles with a per-permission boolean matrix.
     *
     * Useful for building a permission management UI.
     */
    public function withPermissions(): JsonResponse
    {
        $roles = Role::with('permissions')->get();
        $allPermissions = Permission::all()->pluck('name');

        $result = $roles->map(function (Role $role) use ($allPermissions) {
            $assigned = $role->permissions->pluck('name');

            return [
                'role' => $role->name,
                'permissions' => $allPermissions->map(fn ($permission) => [
                    'name' => $permission,
                    'value' => $assigned->contains($permission),
                ])->values(),
            ];
        });

        return response()->json($result);
    }

    /**
     * Sync permissions to one or more roles in a single request.
     *
     * Replaces the full permission set for each role provided.
     */
    #[BodyParameter('roles', 'Array of role/permissions objects.', required: true, type: 'array', example: '[{"role":"Staff","permissions":[{"name":"view users","value":true}]}]')]
    public function syncPermissions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*.role' => ['required', 'string', 'exists:roles,name'],
            'roles.*.permissions' => ['required', 'array'],
            'roles.*.permissions.*.name' => ['required', 'string', 'exists:permissions,name'],
            'roles.*.permissions.*.value' => ['required'],
        ]);

        foreach ($validated['roles'] as $roleData) {
            $role = Role::where('name', $roleData['role'])->firstOrFail();

            $permissionNames = collect($roleData['permissions'])
                ->filter(fn ($p) => filter_var($p['value'], FILTER_VALIDATE_BOOLEAN))
                ->pluck('name')
                ->toArray();

            $role->syncPermissions(Permission::whereIn('name', $permissionNames)->get());
        }

        $summary = collect($validated['roles'])->map(fn ($r) => $r['role'])->implode(', ');

        $this->logActivity('permissions_synced', "Permissions synced for roles: {$summary}.", ['roles' => collect($validated['roles'])->pluck('role'), 'module' => 'Role']);

        return response()->json(['message' => 'Permissions synced successfully.']);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function assignableRoleMap(): array
    {
        return [
            'Admin' => ['Admin', 'Staff', 'Vendor'],
        ];
    }
}
