<?php

namespace App\Http\Resources;

use App\Models\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlatformSettings */
class PlatformSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'commission_bps' => $this->commission_bps,
            'fixed_fee_per_order' => $this->fixed_fee_per_order,
            'service_fee_per_order' => $this->service_fee_per_order,
            'debt_limit_amount' => $this->debt_limit_amount,
            'debt_limit_days' => $this->debt_limit_days,
            'marzpay_enabled' => $this->marzpay_enabled,
            'olycash_enabled' => $this->olycash_enabled,
            'card_enabled' => $this->card_enabled,
            'pay_on_delivery_enabled' => $this->pay_on_delivery_enabled,
            'updated_at' => $this->updated_at,
        ];
    }
}
