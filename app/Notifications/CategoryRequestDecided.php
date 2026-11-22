<?php

namespace App\Notifications;

use App\Enums\CategoryRequestStatus;
use App\Models\CategoryRequest;

/** To whoever asked: the Admin added the category, or said why not. In the bell and by email. */
final class CategoryRequestDecided extends EmailedNotification
{
    private string $name;

    private CategoryRequestStatus $status;

    private ?string $categoryPath;

    private ?string $answer;

    private string $shopId;

    private string $vendorId;

    public function __construct(CategoryRequest $request)
    {
        $this->name = $request->name;
        $this->status = $request->status;
        $this->categoryPath = $request->category?->path();
        $this->answer = $request->answer;
        $this->shopId = $request->shop_id;
        $this->vendorId = $request->shop->vendor_id;
    }

    protected function kind(): string
    {
        return $this->status === CategoryRequestStatus::Added ? 'shop' : 'warning';
    }

    protected function title(): string
    {
        return $this->status === CategoryRequestStatus::Added
            ? "Category ready: {$this->name}"
            : "Category not added: {$this->name}";
    }

    protected function body(): string
    {
        if ($this->status === CategoryRequestStatus::Added) {
            return "Put your products in {$this->categoryPath}.".($this->answer ? " {$this->answer}" : '');
        }

        return "The reason: {$this->answer}";
    }

    protected function link(): string
    {
        return "/dashboard/shops/{$this->shopId}/products";
    }

    protected function vendorId(): string
    {
        return $this->vendorId;
    }
}
