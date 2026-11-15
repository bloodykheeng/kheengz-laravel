<?php

namespace Database\Factories;

use App\Enums\MobileNetwork;
use App\Enums\PayoutDestinationStatus;
use App\Enums\PayoutMethod;
use App\Models\PayoutDestination;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A Verified MTN Mobile Money number unless a state says otherwise.
 *
 * @extends Factory<PayoutDestination>
 */
class PayoutDestinationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'method' => PayoutMethod::MobileMoney,
            'network' => MobileNetwork::Mtn,
            'account_number' => '25677'.fake()->numerify('#######'),
            'account_name' => fake()->name(),
            'status' => PayoutDestinationStatus::Verified,
        ];
    }

    public function awaitingReview(): static
    {
        return $this->state(fn () => ['status' => PayoutDestinationStatus::AwaitingReview, 'status_reason' => 'The names differ.']);
    }
}
