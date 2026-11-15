<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformSettingsResource;
use App\Models\PlatformSettings;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The installation's money rules and payment switches (D15, D23, D28, D34). Admin only (`manage
 * settings`); every change audited. A shop's own commission or limits override these (ShopResource).
 */
#[Group('Settings', 'The platform settings.', weight: 20)]
class PlatformSettingsController extends Controller
{
    use LoggableTrait;

    /**
     * Always a 200 with `{ data }`, like update(): on a fresh install current() creates the row on
     * this first read, and a resource would otherwise answer a read with 201 Created.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => new PlatformSettingsResource(PlatformSettings::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Up to 50%: anything higher is a typing mistake, not a business decision.
            'commission_bps' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'fixed_fee_per_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'service_fee_per_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'debt_limit_amount' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'debt_limit_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'marzpay_enabled' => ['sometimes', 'boolean'],
            'olycash_enabled' => ['sometimes', 'boolean'],
            'card_enabled' => ['sometimes', 'boolean'],
            'pay_on_delivery_enabled' => ['sometimes', 'boolean'],
        ]);

        $settings = PlatformSettings::current();
        $before = $this->captureOriginal($settings);
        $settings->update([...$validated, 'updated_by' => $request->user()->id]);
        $this->logActivity('platform_settings_updated', 'Platform settings updated.', ['changes' => $this->buildChanges($settings, $before)], $settings);

        return response()->json(['message' => 'Settings saved.', 'data' => new PlatformSettingsResource($settings->fresh())]);
    }
}
