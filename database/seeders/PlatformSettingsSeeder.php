<?php

namespace Database\Seeders;

use App\Models\PlatformSettings;
use Illuminate\Database\Seeder;

/**
 * The one platform_settings row, with the defaults the decisions set: 3% commission (D23), no fixed
 * or service fee at launch, debt limit UGX 50,000 or 14 days (D28), MarzPay and OlyCash on (D15).
 * Idempotent: an existing row is left as the Admin set it.
 */
class PlatformSettingsSeeder extends Seeder
{
    public function run(): void
    {
        PlatformSettings::current();
    }
}
