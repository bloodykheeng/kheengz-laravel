<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VendorMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\VendorMemberResource;
use App\Models\User;
use App\Models\Vendor;
use App\Rules\PhoneNumber;
use App\Services\AccountMailer;
use App\Support\Phone;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Who works for a vendor (D26, D31): an Owner adds people as Owner or Staff and gives each Staff
 * member the shops they may run. A vendor always keeps at least one Owner. docs/vendors-and-shops.md.
 */
#[Group('Vendors', 'Vendor applications, vendors, their documents and members.', weight: 3)]
class VendorMemberController extends Controller
{
    use LoggableTrait;

    public function index(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $this->authorize('view', $vendor);

        return VendorMemberResource::collection(
            $vendor->members()
                ->with(['staffShops' => fn ($q) => $q->where('vendor_id', $vendor->id)])
                ->orderByRaw("case when vendor_users.role = 'Owner' then 0 else 1 end")
                ->orderBy('users.name')
                ->get()
        );
    }

    /**
     * Add someone by email. An existing account joins as it is; otherwise an account is made with the
     * Vendor role and its sign-in details are emailed.
     */
    public function store(Request $request, Vendor $vendor, AccountMailer $mailer): JsonResponse
    {
        $this->authorize('own', $vendor);
        $request->merge(['phone' => Phone::international($request->input('phone')) ?? $request->input('phone')]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', new PhoneNumber],
            ...$this->placeRules($vendor, $request),
        ]);

        $existing = User::query()->where('email', $validated['email'])->first();
        abort_if($existing && $vendor->roleOf($existing) !== null, 422, "{$existing?->name} already works for {$vendor->name}.");
        abort_if(! $existing && filled($validated['phone'] ?? null) && User::query()->where('phone', $validated['phone'])->exists(), 422, 'An account with this phone number already exists under another email. Use that email.');

        $plainPassword = null;
        $member = DB::transaction(function () use ($validated, $vendor, $existing, $request, &$plainPassword) {
            $member = $existing ?? User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => $plainPassword = Str::password(12, symbols: false),
                'status' => 'Active',
                'created_by' => $request->user()->id,
            ]);
            if (! $member->hasRole('Vendor')) {
                $member->assignRole('Vendor');
            }

            $vendor->members()->attach($member->id, ['role' => $validated['role'], 'created_by' => $request->user()->id]);
            $member->staffShops()->syncWithoutDetaching($validated['role'] === VendorMemberRole::Staff->value ? ($validated['shop_ids'] ?? []) : []);

            return $member;
        });

        if ($plainPassword !== null) {
            $mailer->welcome($member, $plainPassword);
        }

        $this->logActivity('vendor_member_added', "{$member->name} added to {$vendor->name} as {$validated['role']}.", ['shop_ids' => $validated['shop_ids'] ?? []], $vendor);

        return response()->json([
            'message' => $plainPassword !== null ? "{$member->name} added. Their sign-in details were emailed." : "{$member->name} added.",
            'data' => new VendorMemberResource($this->memberOf($vendor, $member)),
        ], 201);
    }

    /** Change someone's place, or which shops a Staff member runs. */
    public function update(Request $request, Vendor $vendor, User $user): JsonResponse
    {
        $this->authorize('own', $vendor);
        $current = $vendor->roleOf($user);
        abort_if($current === null, 404);

        $validated = $request->validate($this->placeRules($vendor, $request));
        $this->keepAnOwner($vendor, $user, $current, VendorMemberRole::from($validated['role']));

        DB::transaction(function () use ($vendor, $user, $validated) {
            $vendor->members()->updateExistingPivot($user->id, ['role' => $validated['role']]);
            $theirShopsHere = $user->staffShops()->where('vendor_id', $vendor->id)->pluck('shops.id');
            $user->staffShops()->detach($theirShopsHere);
            if ($validated['role'] === VendorMemberRole::Staff->value) {
                $user->staffShops()->attach($validated['shop_ids'] ?? []);
            }
        });

        $this->logActivity('vendor_member_updated', "{$user->name} is now {$validated['role']} at {$vendor->name}.", ['shop_ids' => $validated['shop_ids'] ?? []], $vendor);

        return response()->json(['message' => 'Saved.', 'data' => new VendorMemberResource($this->memberOf($vendor, $user))]);
    }

    /** Remove someone from the vendor (their account stays, for any other vendor they work for). */
    public function destroy(Request $request, Vendor $vendor, User $user): JsonResponse
    {
        $this->authorize('own', $vendor);
        $current = $vendor->roleOf($user);
        abort_if($current === null, 404);
        $this->keepAnOwner($vendor, $user, $current, null);

        DB::transaction(function () use ($vendor, $user) {
            $user->staffShops()->detach($user->staffShops()->where('vendor_id', $vendor->id)->pluck('shops.id'));
            $vendor->members()->detach($user->id);
        });

        $this->logActivity('vendor_member_removed', "{$user->name} removed from {$vendor->name}.", [], $vendor);

        return response()->json(['message' => "{$user->name} no longer works for {$vendor->name}."]);
    }

    /** @return array<string, list<mixed>> */
    private function placeRules(Vendor $vendor, Request $request): array
    {
        return [
            'role' => ['required', Rule::enum(VendorMemberRole::class)],
            'shop_ids' => [Rule::requiredIf($request->input('role') === VendorMemberRole::Staff->value), 'array'],
            'shop_ids.*' => ['string', Rule::exists('shops', 'id')->where('vendor_id', $vendor->id)],
        ];
    }

    /** Never leave a vendor without an Owner: someone must run its money and its shops. */
    private function keepAnOwner(Vendor $vendor, User $user, VendorMemberRole $current, ?VendorMemberRole $next): void
    {
        if ($current !== VendorMemberRole::Owner || $next === VendorMemberRole::Owner) {
            return;
        }

        $owners = $vendor->members()->wherePivot('role', VendorMemberRole::Owner->value)->count();
        abort_if($owners <= 1, 422, "{$user->name} is the only Owner. Make someone else an Owner first.");
    }

    private function memberOf(Vendor $vendor, User $user): User
    {
        return $vendor->members()->with(['staffShops' => fn ($q) => $q->where('vendor_id', $vendor->id)])->whereKey($user->id)->firstOrFail();
    }
}
