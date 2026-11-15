<?php

namespace Database\Factories;

use App\Enums\VendorKind;
use App\Enums\VendorMemberRole;
use App\Enums\VendorStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'kind' => VendorKind::Individual,
            // A NIN's shape: C, then M or F, then 12 letters and digits.
            'national_id_number' => 'C'.fake()->randomElement(['M', 'F']).Str::upper(Str::random(12)),
            'registration_number' => null,
            'phone' => '25677'.fake()->numerify('#######'),
            'email' => fake()->unique()->companyEmail(),
            'status' => VendorStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => VendorStatus::Approved, 'approved_at' => now()]);
    }

    /** With this account as its Owner (an active, confirmed account with the Vendor role when none is given). */
    public function ownedBy(?User $owner = null): static
    {
        return $this->afterCreating(function (Vendor $vendor) use ($owner) {
            $owner ??= User::factory()->create(['status' => 'Active']);
            if (! $owner->hasRole('Vendor')) {
                $owner->assignRole('Vendor');
            }
            $vendor->members()->attach($owner->id, ['role' => VendorMemberRole::Owner->value]);
        });
    }
}
