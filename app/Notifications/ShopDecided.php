<?php

namespace App\Notifications;

use App\Enums\ShopStatus;
use App\Models\Shop;

/** To the vendor's Owners and the shop's Staff: the Admin decided on a shop. In the bell and by email. */
final class ShopDecided extends EmailedNotification
{
    private string $shopId;

    private string $shopName;

    private string $vendorId;

    private ShopStatus $status;

    private ?string $reason;

    private string $address;

    public function __construct(Shop $shop)
    {
        $this->shopId = $shop->id;
        $this->shopName = $shop->name;
        $this->vendorId = $shop->vendor_id;
        $this->status = $shop->status;
        $this->reason = $shop->status_reason;
        $this->address = $shop->address();
    }

    protected function kind(): string
    {
        return $this->status === ShopStatus::Active ? 'shop' : 'warning';
    }

    protected function title(): string
    {
        return match ($this->status) {
            ShopStatus::Active => "{$this->shopName} is open",
            ShopStatus::ChangesRequested => "{$this->shopName} needs changes",
            ShopStatus::Rejected => "{$this->shopName} was not approved",
            ShopStatus::Suspended => "{$this->shopName} is suspended",
            default => "{$this->shopName}: {$this->status->value}",
        };
    }

    protected function body(): string
    {
        return match ($this->status) {
            ShopStatus::Active => "Buyers can find it at {$this->address}.",
            ShopStatus::ChangesRequested => "Make these changes and send it again: {$this->reason}",
            ShopStatus::Rejected, ShopStatus::Suspended => "The reason: {$this->reason}",
            default => 'Open the shop to see more.',
        };
    }

    protected function link(): string
    {
        return "/dashboard/shops/{$this->shopId}";
    }

    protected function vendorId(): string
    {
        return $this->vendorId;
    }
}
