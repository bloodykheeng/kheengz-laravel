<?php

namespace App\Notifications;

use App\Models\Product;

/** To the vendor's Owners and the shop's Staff: Kheengz took a product down (D35). In the bell and by email. */
final class ProductBlocked extends EmailedNotification
{
    private string $productId;

    private string $productName;

    private string $shopName;

    private string $vendorId;

    private string $reason;

    public function __construct(Product $product)
    {
        $this->productId = $product->id;
        $this->productName = $product->name;
        $this->shopName = $product->shop->name;
        $this->vendorId = $product->shop->vendor_id;
        $this->reason = (string) $product->blocked_reason;
    }

    protected function kind(): string
    {
        return 'warning';
    }

    protected function title(): string
    {
        return "{$this->productName} was taken down";
    }

    protected function body(): string
    {
        return "Buyers no longer see it in {$this->shopName}. The reason: {$this->reason} Fix it, then ask support to allow it again.";
    }

    protected function link(): string
    {
        return "/dashboard/products/{$this->productId}";
    }

    protected function vendorId(): string
    {
        return $this->vendorId;
    }
}
