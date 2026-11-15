<?php

namespace App\Services;

use App\Enums\PayoutDestinationStatus;
use App\Enums\PayoutMethod;
use App\Enums\VendorMemberRole;
use App\Jobs\SendSmsJob;
use App\Models\PayoutDestination;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\PayoutDestinationDecided;
use App\Notifications\PayoutDestinationNeedsReview;
use App\Payments\GatewayUnavailable;
use App\Payments\MarzPay\MarzPayClient;
use App\Support\NameMatch;
use App\Support\Phone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Adding and deciding on where a vendor's money goes (D12, D31). A bank account is checked with
 * MarzPay and refused if the bank does not know it. Verified when the account's name matches the
 * vendor or one of its Owners (NameMatch); otherwise, or when MarzPay cannot answer, it waits for
 * the Admin. Every new destination is SMSed to the vendor's phone, so a stolen sign-in cannot quietly
 * send the money elsewhere. docs/vendors-and-shops.md "Payout destinations".
 */
class PayoutDestinations
{
    public function __construct(private MarzPayClient $marzpay, private Notifier $notifier) {}

    /**
     * @param  array{method: string, network?: string|null, bank_name?: string|null, account_number: string, account_name: string}  $input
     */
    public function add(Vendor $vendor, array $input, User $by): PayoutDestination
    {
        $method = PayoutMethod::from($input['method']);
        $fields = $method === PayoutMethod::MobileMoney ? $this->mobileMoney($input) : $this->bank($input);

        $names = [$vendor->name, ...$vendor->members()->wherePivot('role', VendorMemberRole::Owner->value)->pluck('users.name')->all()];
        $nameToMatch = $fields['checked_name'] ?? $input['account_name'];
        $matches = NameMatch::belongsToAny($nameToMatch, $names);

        $status = $fields['unchecked_reason'] === null && $matches ? PayoutDestinationStatus::Verified : PayoutDestinationStatus::AwaitingReview;
        $reason = $fields['unchecked_reason'] ?? ($matches ? null : "The name on the account ({$nameToMatch}) is not {$vendor->name} or one of its Owners.");

        $destination = $vendor->payoutDestinations()->create([
            'method' => $method,
            'network' => $fields['network'],
            'bank_code' => $fields['bank_code'],
            'bank_name' => $fields['bank_name'],
            'account_number' => $fields['account_number'],
            'account_name' => trim($input['account_name']),
            'checked_name' => $fields['checked_name'],
            'status' => $status,
            'status_reason' => $reason,
            'created_by' => $by->id,
        ]);

        SendSmsJob::dispatch($vendor->phone, "{$vendor->name}: a payout destination was added: {$destination->label()}. If this was not you, call support at once.");
        if ($status === PayoutDestinationStatus::AwaitingReview) {
            $this->notifier->admins(new PayoutDestinationNeedsReview($destination), $by);
        }

        return $destination;
    }

    public function approve(PayoutDestination $destination, User $by): void
    {
        $this->decide($destination, PayoutDestinationStatus::Verified, null, $by);
    }

    public function reject(PayoutDestination $destination, string $reason, User $by): void
    {
        $this->decide($destination, PayoutDestinationStatus::Rejected, $reason, $by);
    }

    private function decide(PayoutDestination $destination, PayoutDestinationStatus $to, ?string $reason, User $by): void
    {
        if ($destination->status !== PayoutDestinationStatus::AwaitingReview) {
            throw ValidationException::withMessages(['status' => "This payout destination is {$destination->status->value} already."]);
        }

        $destination->update(['status' => $to, 'status_reason' => $reason, 'reviewed_at' => now(), 'reviewed_by' => $by->id]);
        Notification::send($destination->vendor->members()->wherePivot('role', VendorMemberRole::Owner->value)->get(), new PayoutDestinationDecided($destination));
    }

    /**
     * The network read off the number's prefix (076-079 MTN, 070/074/075 Airtel) must be the one chosen.
     *
     * @param  array<string, mixed>  $input
     * @return array{network: string, bank_code: null, bank_name: null, account_number: string, checked_name: null, unchecked_reason: null}
     */
    private function mobileMoney(array $input): array
    {
        $number = (string) Phone::normalize((string) $input['account_number']);
        $network = Phone::network($number);

        if ($network === null || ($input['network'] ?? null) !== $network->value) {
            throw ValidationException::withMessages(['account_number' => $network === null
                ? 'Enter an MTN or Airtel Uganda number.'
                : "This is an {$network->value} number. Choose {$network->value}, or enter the other number."]);
        }

        return ['network' => $network->value, 'bank_code' => null, 'bank_name' => null, 'account_number' => $number, 'checked_name' => null, 'unchecked_reason' => null];
    }

    /**
     * Checked with MarzPay. A bank that does not know the account is refused now; MarzPay being
     * unavailable leaves it for the Admin rather than stopping the vendor.
     *
     * @param  array<string, mixed>  $input
     * @return array{network: null, bank_code: string|null, bank_name: string, account_number: string, checked_name: string|null, unchecked_reason: string|null}
     */
    private function bank(array $input): array
    {
        $bankName = trim((string) $input['bank_name']);
        $account = (string) preg_replace('/\s+/', '', (string) $input['account_number']);

        try {
            $check = $this->marzpay->validateBankAccount($bankName, $account);
        } catch (GatewayUnavailable $e) {
            Log::warning('Bank account not checked', ['bank' => $bankName, 'error' => $e->getMessage()]);

            return ['network' => null, 'bank_code' => null, 'bank_name' => $bankName, 'account_number' => $account, 'checked_name' => null, 'unchecked_reason' => 'The bank could not be asked just now, so the Admin will check this account.'];
        }

        if (! $check->valid) {
            throw ValidationException::withMessages(['account_number' => "{$bankName} does not recognise this account ({$check->reason}). Check the number and the bank."]);
        }

        return ['network' => null, 'bank_code' => $check->bankCode, 'bank_name' => $bankName, 'account_number' => $account, 'checked_name' => $check->accountName, 'unchecked_reason' => null];
    }
}
