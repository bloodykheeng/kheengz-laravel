<?php

namespace Database\Factories;

use App\Enums\ShopStatus;
use App\Enums\ShopType;
use App\Models\Shop;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'name' => fake()->company(),
            'slug' => fake()->unique()->lexify('shop??????'),
            'shop_type' => ShopType::HouseholdAndHome,
            'description' => fake()->sentence(),
            'phone' => '25677'.fake()->numerify('#######'),
            'status' => ShopStatus::Draft,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => ShopStatus::Active, 'approved_at' => now()]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => ['status' => ShopStatus::PendingReview]);
    }
}
