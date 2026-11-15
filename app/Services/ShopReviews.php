<?php

namespace App\Services;

use App\Enums\PayoutDestinationStatus;
use App\Enums\ShopReviewDecision;
use App\Enums\ShopStatus;
use App\Enums\VendorStatus;
use App\Jobs\SendSmsJob;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\ShopDecided;
use App\Notifications\ShopSubmitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Every change of a shop's status (D31), and only the legal ones: each writes a shop_reviews row,
 * updates the shop, and tells the people concerned. A move from the wrong status (a decision someone
 * else already made) is refused as a 422 on `status`, so the screen can refetch and say so.
 * docs/vendors-and-shops.md.
 */
class ShopReviews
{
    public function __construct(private Notifier $notifier) {}

    /** The vendor sends a Draft, or a shop sent back for changes, to the Admin. */
    public function submit(Shop $shop, User $by): void
    {
        $vendor = $shop->vendor;
        if (in_array($vendor->status, [VendorStatus::Rejected, VendorStatus::Suspended], true)) {
            throw ValidationException::withMessages(['status' => "Your vendor account is {$vendor->status->value}, so shops cannot be sent for review."]);
        }

        if ($shop->payout_destination_id === null) {
            throw ValidationException::withMessages(['payout_destination_id' => 'Choose where this shop is paid before sending it for review.']);
        }

        $this->move($shop, [ShopStatus::Draft, ShopStatus::ChangesRequested], ShopStatus::PendingReview, ShopReviewDecision::Submitted, null, $by);
        $this->notifier->admins(new ShopSubmitted($shop), $by);
    }

    /** The Admin approves: the shop sells as soon as its vendor is approved too. */
    public function approve(Shop $shop, User $by): void
    {
        if ($shop->vendor->status !== VendorStatus::Approved) {
            throw ValidationException::withMessages(['status' => 'Approve the vendor first: a shop sells only once the person behind it is verified.']);
        }

        if ($shop->payoutDestination?->status !== PayoutDestinationStatus::Verified) {
            throw ValidationException::withMessages(['status' => 'Approve the shop\'s payout destination first: money must only go to an account we have checked.']);
        }

        $this->move($shop, [ShopStatus::PendingReview], ShopStatus::Active, ShopReviewDecision::Approved, null, $by, [
            'approved_at' => now(),
            'approved_by' => $by->id,
        ]);
        $this->tell($shop, "Your shop {$shop->name} is approved and open: {$shop->address()}");
    }

    public function requestChanges(Shop $shop, string $reason, User $by): void
    {
        $this->move($shop, [ShopStatus::PendingReview], ShopStatus::ChangesRequested, ShopReviewDecision::ChangesRequested, $reason, $by);
        $this->tell($shop, "Your shop {$shop->name} needs changes before it can open: {$reason}");
    }

    public function reject(Shop $shop, string $reason, User $by): void
    {
        $this->move($shop, [ShopStatus::PendingReview], ShopStatus::Rejected, ShopReviewDecision::Rejected, $reason, $by);
        $this->tell($shop, "Your shop {$shop->name} was not approved: {$reason}");
    }

    public function suspend(Shop $shop, string $reason, User $by): void
    {
        $this->move($shop, [ShopStatus::Active], ShopStatus::Suspended, ShopReviewDecision::Suspended, $reason, $by);
        $this->tell($shop, "Your shop {$shop->name} is suspended: {$reason}");
    }

    public function reinstate(Shop $shop, User $by): void
    {
        $this->move($shop, [ShopStatus::Suspended], ShopStatus::Active, ShopReviewDecision::Reinstated, null, $by);
        $this->tell($shop, "Your shop {$shop->name} is open again.");
    }

    /**
     * The vendor closes a shop of their own. Not while it is suspended: closing must never be a way
     * round the Admin's decision.
     */
    public function close(Shop $shop, ?string $reason, User $by): void
    {
        $this->move($shop, [ShopStatus::Draft, ShopStatus::ChangesRequested, ShopStatus::Active, ShopStatus::Rejected, ShopStatus::PendingReview], ShopStatus::Closed, ShopReviewDecision::Closed, $reason, $by);
    }

    /**
     * @param  list<ShopStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    private function move(Shop $shop, array $from, ShopStatus $to, ShopReviewDecision $decision, ?string $reason, User $by, array $extra = []): void
    {
        DB::transaction(function () use ($shop, $from, $to, $decision, $reason, $by, $extra) {
            // Locked and read again: two reviewers pressing at once must not both decide.
            $current = Shop::query()->whereKey($shop->id)->lockForUpdate()->firstOrFail();

            if (! in_array($current->status, $from, true)) {
                throw ValidationException::withMessages(['status' => "This shop is {$current->status->value} now, so it cannot be ".strtolower($decision->value).'.']);
            }

            $shop->forceFill([
                ...$extra,
                'status' => $to,
                'status_reason' => $reason,
                'updated_by' => $by->id,
            ])->save();

            $shop->reviews()->create(['decision' => $decision, 'reason' => $reason, 'created_by' => $by->id]);
        });
    }

    /** The vendor's Owners and the shop's Staff, in the bell and by email; the shop's phone by SMS. */
    private function tell(Shop $shop, string $sms): void
    {
        $people = $shop->vendor->members()->wherePivot('role', 'Owner')->get()->merge($shop->staff()->get())->unique('id');
        Notification::send($people, new ShopDecided($shop));
        SendSmsJob::dispatch($shop->phone, $sms);
    }
}
