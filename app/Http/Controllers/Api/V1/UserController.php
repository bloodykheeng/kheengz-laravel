<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PhotoSource;
use App\Events\UserEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\AccountMailer;
use App\Services\EmailVerification;
use App\Support\ImageFromLink;
use App\Support\PerPage;
use App\Support\Phone;
use App\Traits\BulkDeletes;
use App\Traits\HandlePhotoTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;
use Throwable;

/** Accounts: Admin, Staff and Vendor (workspace D26). Kept by an Admin. Buyers never have one. */
#[Group('Users', 'Manage user accounts.', weight: 3)]
class UserController extends Controller
{
    use BulkDeletes;
    use HandlePhotoTrait;

    #[QueryParameter('filter[search]', 'Name, email, username or phone.', type: 'string')]
    #[QueryParameter('filter[status]', 'Active or Inactive.', type: 'string')]
    #[QueryParameter('filter[role]', 'Admin, Staff or Vendor.', type: 'string')]
    #[QueryParameter('filter[email_status]', 'Confirmed or Not confirmed: whether the sign-up code was entered.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = QueryBuilder::for(User::query())
            ->allowedFilters(
                AllowedFilter::scope('search'),
                AllowedFilter::exact('status'),
                AllowedFilter::callback('role', fn (Builder $q, $value) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $value))),
                // Separate from Status: Status is the Admin's on/off switch, this is whether the
                // person entered the code from their confirmation email.
                AllowedFilter::callback('email_status', fn (Builder $q, $value) => $value === 'Confirmed'
                    ? $q->whereNotNull('email_verified_at')
                    : $q->whereNull('email_verified_at')),
                // Who is online is socket state the browser already knows: it sends the ids.
                AllowedFilter::exact('id'),
                AllowedFilter::callback('not_id', fn (Builder $q, $value) => $q->whereNotIn('id', (array) $value)),
            )
            ->allowedSorts('name', 'email', 'status', 'email_verified_at', 'created_at', 'lastlogin')
            ->allowedIncludes(
                AllowedInclude::relationship('created_by', 'createdBy'),
                AllowedInclude::relationship('updated_by', 'updatedBy'),
            )
            ->defaultSort('-created_at')
            ->orderBy('id', 'desc')
            ->with('roles')
            ->paginate(PerPage::from($request))
            ->appends($request->query());

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load(['roles', 'createdBy', 'updatedBy']));
    }

    #[BodyParameter('role', 'Admin, Staff or Vendor. Owner or Staff in one shop is per shop.', required: true, type: 'string', example: 'Staff')]
    public function store(Request $request, AccountMailer $mailer, ImageFromLink $images): JsonResponse
    {
        $this->normalizePhone($request);

        $validated = $request->validate($this->rules());

        $role = $validated['role'];
        $plainPassword = $validated['password'] ?? Str::password(12, symbols: false);

        // A file, or an image link downloaded once and kept as our copy.
        $photoLink = $validated['photo_link'] ?? null;
        $photoPath = $request->hasFile('photo')
            ? $this->handlePhotoUpload($request->file('photo'), 'users/photos/'.date('Y/m'))
            : (filled($photoLink) ? $images->store($photoLink, 'users/photos/'.date('Y/m'), 'photo_link') : null);
        $photoSource = $request->hasFile('photo') ? PhotoSource::Upload : (filled($photoLink) ? PhotoSource::Link : null);

        $data = collect($validated)->except(['role', 'photo', 'photo_link'])->all();

        try {
            $user = DB::transaction(function () use ($data, $plainPassword, $photoPath, $photoSource, $photoLink, $role, $request) {
                $user = User::create([
                    ...$data,
                    'password' => $plainPassword,
                    'photo_url' => $photoPath,
                    'photo_source' => $photoSource,
                    'photo_source_url' => $photoSource === PhotoSource::Link ? trim((string) $photoLink) : null,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
                $user->assignRole($role);
                // An Admin made it and emailed the sign-in details: no code to confirm.
                $user->forceFill(['email_verified_at' => now()])->save();

                return $user;
            });
        } catch (Throwable $e) {
            $this->deletePhoto($photoPath);

            throw $e;
        }

        $this->logActivity('user_created', "User '{$user->name}' was created.", [
            'snapshot' => $this->buildModelSnapshot($user),
        ], $user);

        broadcast(new UserEvent('created', user: $user))->toOthers();
        $mailer->welcome($user, $plainPassword);

        return response()->json(['message' => 'Account created. Sign-in details were emailed.', 'data' => new UserResource($user)], 201);
    }

    public function update(Request $request, User $user, AccountMailer $mailer, ImageFromLink $images): JsonResponse
    {
        $this->normalizePhone($request);

        $validated = $request->validate($this->rules($user));

        // Nobody demotes or deactivates themselves out of the platform by accident.
        if ($user->is($request->user()) && ((isset($validated['role']) && $validated['role'] !== $user->role)
            || (($validated['status'] ?? 'Active') !== 'Active'))) {
            abort(422, 'You cannot change your own role or deactivate your own account.');
        }

        $role = $validated['role'] ?? null;
        $passwordChanged = filled($validated['password'] ?? null);
        $data = collect($validated)->except(['role', 'photo', 'photo_link', 'remove_photo'])->filter(fn ($v, $k) => $k !== 'password' || filled($v))->all();

        $oldPhoto = $user->getRawOriginal('photo_url');
        $replacePhoto = false;
        if ($request->hasFile('photo')) {
            $data['photo_url'] = $this->handlePhotoUpload($request->file('photo'), 'users/photos/'.date('Y/m'));
            $data['photo_source'] = PhotoSource::Upload;
            $data['photo_source_url'] = null;
            $replacePhoto = true;
        } elseif (filled($validated['photo_link'] ?? null)) {
            $data['photo_url'] = $images->store($validated['photo_link'], 'users/photos/'.date('Y/m'), 'photo_link');
            $data['photo_source'] = PhotoSource::Link;
            $data['photo_source_url'] = trim($validated['photo_link']);
            $replacePhoto = true;
        } elseif ($request->boolean('remove_photo')) {
            $data['photo_url'] = null;
            $data['photo_source'] = null;
            $data['photo_source_url'] = null;
            $replacePhoto = true;
        }

        $before = $this->captureOriginal($user);

        DB::transaction(function () use ($user, $data, $role, $request) {
            $user->update([...$data, 'updated_by' => $request->user()->id]);

            if ($role) {
                $user->syncRoles([$role]);
            }
        });

        if ($replacePhoto) {
            $this->deletePhoto($oldPhoto);
        }

        $this->logActivity('user_updated', "User '{$user->name}' was updated.", ['changes' => $this->buildChanges($user, $before)], $user);

        broadcast(new UserEvent('updated', user: $user))->toOthers();
        $mailer->updated($user, $passwordChanged);

        return response()->json(['message' => 'Account saved.', 'data' => new UserResource($user->load('roles'))]);
    }

    /**
     * Email a new sign-up code to someone who has not confirmed their address yet, for an Admin
     * helping a person whose code expired or went to spam.
     */
    public function resendVerification(Request $request, User $user, EmailVerification $verification): JsonResponse
    {
        abort_if($user->email_verified_at !== null, 422, "{$user->name} has already confirmed their email.");
        abort_if(blank($user->email), 422, "{$user->name} has no email address to send a code to.");

        if (! $verification->send($user, $request->user())) {
            return response()->json(['message' => 'We could not send the code just now. Check the mail settings and try again.'], 503);
        }

        return response()->json(['message' => "A new code was emailed to {$user->email}. It works for ".EmailVerification::CODE_TTL_MINUTES.' minutes.']);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            abort(422, 'You cannot delete your own account.');
        }

        $snapshot = $this->buildModelSnapshot($user);
        $userId = $user->id;

        // User::booted() removes their tokens and photo.
        $user->delete();

        $this->logActivity('user_deleted', "User '{$user->name}' was deleted.", ['snapshot' => $snapshot], $user);
        broadcast(new UserEvent('deleted', userId: $userId))->toOthers();

        return response()->json(['message' => 'Account deleted.']);
    }

    /** Delete several accounts, all or none. Never your own. */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = $this->bulkIds($request);
        abort_if(in_array($request->user()->id, $ids, true), 422, 'You cannot delete your own account.');

        $users = User::query()->whereKey($ids)->get();
        abort_if($users->count() !== count($ids), 404, 'Some of these accounts no longer exist.');

        $count = $this->deleteManyOrFail($users, 'user_bulk_deleted', count($ids).' account(s) deleted.', 'User');

        foreach ($ids as $id) {
            broadcast(new UserEvent('deleted', userId: $id))->toOthers();
        }

        return response()->json(['message' => $count === 1 ? 'Account deleted.' : "{$count} accounts deleted."]);
    }

    /** Store phones in the one shape the sign-in lookup expects. */
    private function normalizePhone(Request $request): void
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => Phone::international($request->input('phone')) ?? preg_replace('/\D/', '', (string) $request->input('phone'))]);
        }
    }

    /** @return array<string, mixed> */
    private function rules(?User $user = null): array
    {
        $creating = $user === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'email' => [$creating ? 'required' : 'sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'username' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user?->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', new PhoneNumber, Rule::unique('users', 'phone')->ignore($user?->id)],
            'allow_notifications' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(['Active', 'Inactive'])],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(['Admin', 'Staff', 'Vendor'])],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:20480'],
            'remove_photo' => ['sometimes', 'boolean'],
            'photo_link' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
        ];
    }
}
