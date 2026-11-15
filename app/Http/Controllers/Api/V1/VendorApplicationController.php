<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VendorKind;
use App\Enums\VendorMemberRole;
use App\Enums\VendorStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use App\Rules\NationalIdNumber;
use App\Rules\PhoneNumber;
use App\Services\EmailVerification;
use App\Support\Phone;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * "Sell on Kheengz": someone with no account applies to become a vendor. It makes their account
 * (role Vendor, email to be confirmed with a code) and the vendor, Pending, with them as its Owner.
 * They then sign in, upload their papers and add their first shop; the Admins are told once the
 * papers are complete (VendorDocumentController). docs/vendors-and-shops.md.
 */
#[Group('Vendors', 'Vendor applications, vendors, their documents and members.', weight: 3)]
class VendorApplicationController extends Controller
{
    use LoggableTrait;

    public function store(Request $request, EmailVerification $verification): JsonResponse
    {
        $this->normalise($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', new PhoneNumber, 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'vendor_name' => ['required', 'string', 'min:2', 'max:160'],
            'kind' => ['required', Rule::enum(VendorKind::class)],
            'national_id_number' => ['required', 'string', new NationalIdNumber, 'unique:vendors,national_id_number'],
            'registration_number' => [Rule::requiredIf($request->input('kind') === VendorKind::Company->value), 'nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'accept_terms' => ['accepted'],
        ], [
            'email.unique' => 'An account with this email already exists. Sign in instead.',
            'phone.unique' => 'An account with this number already exists. Sign in instead.',
            'national_id_number.unique' => 'A vendor with this National ID Number already exists. Sign in to that account, or contact support.',
            'registration_number.required' => 'Enter the company registration number from URSB.',
            'accept_terms.accepted' => 'Agree to the Seller Terms and the Privacy Policy to apply.',
        ]);

        [$user, $vendor] = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => $validated['password'],
                'status' => 'Active',
                'terms_accepted_at' => now(),
            ]);
            $user->assignRole('Vendor');

            $vendor = Vendor::create([
                'name' => $validated['vendor_name'],
                'kind' => $validated['kind'],
                'national_id_number' => $validated['national_id_number'],
                'registration_number' => $validated['kind'] === VendorKind::Company->value ? $validated['registration_number'] : null,
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'address' => $validated['address'] ?? null,
                'status' => VendorStatus::Pending,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $vendor->members()->attach($user->id, ['role' => VendorMemberRole::Owner->value, 'created_by' => $user->id]);

            return [$user, $vendor];
        });

        $sent = $verification->send($user);

        $this->logActivity('vendor_applied', "{$user->name} applied to sell as {$vendor->name}.", ['snapshot' => $this->buildModelSnapshot($vendor)], $vendor, null, $user);

        return response()->json([
            'message' => $sent
                ? "We sent a 6-digit code to {$user->email}. Enter it to finish, then add your papers and your first shop."
                : 'Your application is in, but we could not send the code just now. Press Send a new code.',
            'data' => ['email' => $user->email, 'verification_required' => true, 'code_sent' => $sent],
        ], 201);
    }

    /** Phones as digits with 256; a NIN upper-cased without spaces, as people type it either way. */
    private function normalise(Request $request): void
    {
        $request->merge([
            'phone' => Phone::international($request->input('phone')) ?? $request->input('phone'),
            'national_id_number' => strtoupper((string) preg_replace('/\s+/', '', (string) $request->input('national_id_number'))),
        ]);
    }
}
