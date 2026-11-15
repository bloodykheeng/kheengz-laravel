<?php

namespace App\Notifications;

use App\Models\Shop;

/** To the Admins: a shop was sent for review. */
final class ShopSubmitted extends KheengzNotification
{
    private string $shopId;

    private string $shopName;

    private string $vendorId;

    private string $vendorName;

    public function __construct(Shop $shop)
    {
        $this->shopId = $shop->id;
        $this->shopName = $shop->name;
        $this->vendorId = $shop->vendor_id;
        $this->vendorName = $shop->vendor->name;
    }

    protected function kind(): string
    {
        return 'shop';
    }

    protected function title(): string
    {
        return 'Shop waiting for review';
    }

    protected function body(): string
    {
        return "{$this->vendorName} sent {$this->shopName} for review.";
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
