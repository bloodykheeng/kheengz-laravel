<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShopResource;
use App\Models\Shop;
use App\Services\ShopReviews;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Admin's decisions on a shop (D31): approve, ask for changes, reject, suspend, reinstate. */
#[Group('Shops', 'Shops, their review and their locations.', weight: 4)]
class ShopReviewController extends Controller
{
    use LoggableTrait;

    public function __construct(private ShopReviews $reviews) {}

    public function approve(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('review', $shop);
        $this->reviews->approve($shop, $request->user());
        $this->logActivity('shop_approved', "Shop {$shop->name} approved.", [], $shop);

        return $this->answer($shop, "{$shop->name} is open. The vendor has been told.");
    }

    public function requestChanges(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('review', $shop);
        $reason = $this->reason($request);
        $this->reviews->requestChanges($shop, $reason, $request->user());
        $this->logActivity('shop_changes_requested', "Changes asked of shop {$shop->name}: {$reason}", ['reason' => $reason], $shop);

        return $this->answer($shop, "{$shop->name} is sent back with what to change.");
    }

    public function reject(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('review', $shop);
        $reason = $this->reason($request);
        $this->reviews->reject($shop, $reason, $request->user());
        $this->logActivity('shop_rejected', "Shop {$shop->name} rejected: {$reason}", ['reason' => $reason], $shop);

        return $this->answer($shop, "{$shop->name} is rejected. The vendor has been told why.");
    }

    public function suspend(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('review', $shop);
        $reason = $this->reason($request);
        $this->reviews->suspend($shop, $reason, $request->user());
        $this->logActivity('shop_suspended', "Shop {$shop->name} suspended: {$reason}", ['reason' => $reason], $shop);

        return $this->answer($shop, "{$shop->name} is suspended. The vendor's other shops keep selling.");
    }

    public function reinstate(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('review', $shop);
        $this->reviews->reinstate($shop, $request->user());
        $this->logActivity('shop_reinstated', "Shop {$shop->name} reinstated.", [], $shop);

        return $this->answer($shop, "{$shop->name} is open again.");
    }

    private function reason(Request $request): string
    {
        return $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:500']],
            ['reason.required' => 'Say why, in a sentence the vendor will read.'],
        )['reason'];
    }

    private function answer(Shop $shop, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => new ShopResource($shop->fresh('vendor'))]);
    }
}
