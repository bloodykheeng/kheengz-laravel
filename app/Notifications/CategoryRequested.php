<?php

namespace App\Notifications;

use App\Models\CategoryRequest;

/** To the Admins: a shop asked for a category the tree lacks (D32). In the bell. */
final class CategoryRequested extends KheengzNotification
{
    private string $name;

    private string $shopName;

    private string $vendorId;

    public function __construct(CategoryRequest $request)
    {
        $this->name = $request->name;
        $this->shopName = $request->shop->name;
        $this->vendorId = $request->shop->vendor_id;
    }

    protected function kind(): string
    {
        return 'shop';
    }

    protected function title(): string
    {
        return 'Category asked for';
    }

    protected function body(): string
    {
        return "{$this->shopName} asked for \"{$this->name}\".";
    }

    protected function link(): string
    {
        return '/dashboard/catalogue/requests';
    }

    protected function vendorId(): string
    {
        return $this->vendorId;
    }
}
