<?php

namespace App\Notifications;

use App\Models\ProductVariant;

/** To the vendor's Owners and the shop's Staff: a variant fell to its low-stock line, or ran out. In the bell. */
final class StockRunningLow extends KheengzNotification
{
    private string $productId;

    private string $name;

    private string $available;

    private string $vendorId;

    public function __construct(ProductVariant $variant, string $available)
    {
        $label = $variant->label();
        $this->productId = $variant->product_id;
        $this->name = $variant->product->name.($label !== '' ? ", {$label}" : '');
        $this->available = $available;
        $this->vendorId = $variant->product->shop->vendor_id;
    }

    protected function kind(): string
    {
        return 'stock';
    }

    protected function title(): string
    {
        return (float) $this->available <= 0 ? "Out of stock: {$this->name}" : "Running low: {$this->name}";
    }

    protected function body(): string
    {
        return (float) $this->available <= 0 ? 'Buyers can no longer order it. Receive more on its stock card.' : "{$this->available} left to sell.";
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
