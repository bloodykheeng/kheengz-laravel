<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PhotoSource;
use App\Events\UserEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\ImageFromLink;
use App\Traits\HandlePhotoTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

#[Group('Auth Check', 'Authenticated user profile and device endpoints.', weight: 4)]
class AuthCheckController extends Controller
{
    // HandlePhotoTrait already pulls in LoggableTrait, which is where logActivity(),
    // captureOriginal() and buildChanges() come from.
    use HandlePhotoTrait;

    /**
     * Get the authenticated user's full profile.
     *
     * Returns the current user's data with all relationships, role, and permissions.
     * Revokes all tokens and returns 401 if the account is inactive.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'roles',
            'createdBy',
            'updatedBy',
        ]);

        if ($user->status !== 'Active') {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Your account is not active. Please contact '.config('app.name').' support.',
            ], 401);
        }

        return response()->json([
            'message' => "Welcome back, {$user->name}",
            'user' => new UserResource($user),
            'permissions' => $user->getAllPermissions()->pluck('name'),

        ]);
    }

    /**
     * Updates the authenticated user's own profile.
     *
     * Self-service only. Role and status are deliberately
     * not accepted here, deciding what the account may reach, so they stay with the
     * administrator endpoints. Changing your own password is allowed, but every save
     * re-checks the current one first.
     *
     * POST rather than PATCH because the photo arrives as multipart.
     */
    #[BodyParameter('name', 'Full name.', required: true, type: 'string', example: 'John Doe')]
    #[BodyParameter('email', 'Email address. Keep at least one of email, phone or username.', type: 'string', example: 'john@example.com')]
    #[BodyParameter('username', 'Optional username.', type: 'string')]
    #[BodyParameter('phone', 'Optional phone number.', type: 'string', example: '256700000000')]
    #[BodyParameter('timezone', 'IANA timezone.', type: 'string', example: 'Africa/Kampala')]
    #[BodyParameter('allow_notifications', 'Whether to receive notifications.', type: 'boolean')]
    #[BodyParameter('current_password', 'Required to save any change.', required: true, type: 'string')]
    #[BodyParameter('new_password', 'Optional new password, needs new_password_confirmation.', type: 'string')]
    #[BodyParameter('photo.file_path', 'Optional replacement photo.', type: 'file')]
    #[BodyParameter('photo_link', 'Optional image link instead of a file: downloaded once and kept as our copy.', type: 'string')]
    #[BodyParameter('remove_photo', 'Clear the existing photo.', type: 'boolean')]
    public function updateProfile(Request $request, ImageFromLink $images): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->normalizePhone($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Any of the three; that at least one remains is checked below.
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'username' => ['nullable', 'string', 'max:100', 'unique:users,username,'.$user->id],
            'phone' => ['nullable', 'string', 'max:20', new PhoneNumber, 'unique:users,phone,'.$user->id],
            'timezone' => ['nullable', 'string', 'max:100'],
            'allow_notifications' => ['sometimes', 'boolean'],
            // Bounded so an oversized value cannot burn CPU in the hasher.
            'current_password' => ['required', 'string', 'max:255'],
            'new_password' => ['nullable', 'string', 'min:8', 'max:255', 'confirmed'],
            'photo' => ['nullable', 'array'],
            'photo.file_path' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:20480'],
            // Removing the photo in the picker sends no file, which is indistinguishable
            // from "didn't touch it", so clearing needs an explicit signal.
            'remove_photo' => ['sometimes', 'boolean'],
            'photo_link' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // Every one of the three is sent on this form, so the payload alone
        // says whether the account stays reachable.
        if (! filled($validated['email'] ?? null) && ! filled($validated['phone'] ?? null) && ! filled($validated['username'] ?? null)) {
            throw ValidationException::withMessages([
                'email' => ['Keep an email, a phone number or a username: at least one is needed.'],
            ]);
        }

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'username' => $validated['username'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'timezone' => $validated['timezone'] ?? $user->timezone,
            'allow_notifications' => $validated['allow_notifications'] ?? $user->allow_notifications,
            'updated_by' => $user->id,
        ];

        $passwordChanged = ! empty($validated['new_password']);
        if ($passwordChanged) {
            $updateData['password'] = Hash::make($validated['new_password']);
        }

        // Same three-part ordering as UserController::update: upload before the write,
        // delete the superseded file only after it succeeds. A failed save cannot
        // un-delete a photo, so removing the old one up front would destroy it for nothing.
        $oldPhotoPath = $user->getRawOriginal('photo_url');
        $newPhotoPath = null;
        $photoReplaced = false;

        if ($request->hasFile('photo.file_path')) {
            $newPhotoPath = $this->handlePhotoUpload(
                $validated['photo']['file_path'],
                'users/photos/'.date('Y/m')
            );
            $updateData['photo_url'] = $newPhotoPath;
            $updateData['photo_source'] = PhotoSource::Upload;
            $updateData['photo_source_url'] = null;
            $photoReplaced = true;
        } elseif (filled($validated['photo_link'] ?? null)) {
            // Downloaded once and kept as our copy (ImageFromLink), like an upload.
            $newPhotoPath = $images->store($validated['photo_link'], 'users/photos/'.date('Y/m'), 'photo_link');
            $updateData['photo_url'] = $newPhotoPath;
            $updateData['photo_source'] = PhotoSource::Link;
            $updateData['photo_source_url'] = trim($validated['photo_link']);
            $photoReplaced = true;
        } elseif ($request->boolean('remove_photo')) {
            $updateData['photo_url'] = null;
            $updateData['photo_source'] = null;
            $updateData['photo_source_url'] = null;
            $photoReplaced = true;
        }

        $before = $this->captureOriginal($user);

        try {
            $user->update($updateData);
        } catch (Throwable $e) {
            $this->deletePhoto($newPhotoPath);

            throw $e;
        }

        if ($photoReplaced) {
            $this->deletePhoto($oldPhotoPath);
        }

        $changes = $this->buildChanges($user, $before);

        // Subject only. The causer defaults to Auth::user(), which on this endpoint is
        // the same person: self-service edits are the one case where actor and subject
        // are meant to match.
        $this->logActivity(
            'user_profile_updated',
            "User '{$user->name}' updated their own profile.".($passwordChanged ? ' Password was changed.' : ''),
            ['changes' => $changes, 'password_changed' => $passwordChanged],
            $user,
        );

        broadcast(new UserEvent('updated', user: $user))->toOthers();

        $this->sendProfileUpdatedNotifications($user, $passwordChanged);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Check if the current token is still valid.
     *
     * Lightweight endpoint for mobile/web apps to verify authentication status.
     */
    public function appStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'authenticated' => true,
            'message' => "Token valid. Welcome back, {$user->name}.",
            'user_id' => $user->id,
        ]);
    }

    /**
     * Reduces a submitted phone number to the stored form, digits only.
     *
     * Mirrors `UserController::normalizePhone()`.
     */
    private function normalizePhone(Request $request): void
    {
        if (filled($request->input('phone'))) {
            $request->merge(['phone' => preg_replace('/\D/', '', (string) $request->input('phone'))]);
        }
    }

    /**
     * Tells the user their own profile changed.
     *
     * Worth sending even though they made the change: it is the signal that reaches
     * them if somebody else did it with a stolen session.
     */
    private function sendProfileUpdatedNotifications(User $user, bool $passwordChanged): void
    {
        if (! empty($user->email)) {
            try {
                Mail::send('emails.users.user-account-updated', [
                    'user' => $user,
                    'password_changed' => $passwordChanged,
                ], function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('Your '.config('app.name').' profile was updated');
                });
            } catch (Exception $e) {
                $this->logActivity(
                    'email_sending_failed_update',
                    "Failed to send profile updated notification to '{$user->email}'. Error: {$e->getMessage()}",
                    [],
                    $user,
                );
            }
        }

    }
}
