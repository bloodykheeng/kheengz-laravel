<?php

/**
 * Where a vendor's money goes (D12, D31): Mobile Money by its network's prefix, bank accounts checked
 * with MarzPay (faked here at the HTTP level, so the real client runs), Verified only when the name
 * matches the vendor or an Owner, and every new one SMSed to the vendor.
 */

use App\Enums\PayoutDestinationStatus;
use App\Jobs\SendSmsJob;
use App\Models\PayoutDestination;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\PayoutDestinationNeedsReview;
use App\Support\NameMatch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    Queue::fake();
    config(['services.marzpay.api_key' => 'key', 'services.marzpay.api_secret' => 'secret']);

    $this->owner = User::factory()->create(['name' => 'Fahim Kato']);
    $this->vendor = Vendor::factory()->approved()->ownedBy($this->owner)->create(['name' => 'Home Nest Ltd']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Admin');
    $this->actingAs($this->owner, 'sanctum');
});

function addDestination(array $input): TestResponse
{
    return test()->postJson('/api/v1/vendors/'.test()->vendor->id.'/payout-destinations', $input);
}

it('verifies a Mobile Money number in the Owner\'s name, and texts the vendor about it', function () {
    addDestination(['method' => 'Mobile Money', 'network' => 'MTN', 'account_number' => '0772 123 456', 'account_name' => 'KATO FAHIM'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'Verified')
        ->assertJsonPath('data.account_number', '256772123456')
        ->assertJsonPath('data.label', 'MTN Mobile Money · 0772123456');

    Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->phone === $this->vendor->phone && str_contains($job->message, 'If this was not you'));
    Notification::assertNothingSent();
});

it('refuses a number on the other network', function () {
    addDestination(['method' => 'Mobile Money', 'network' => 'Airtel', 'account_number' => '0772123456', 'account_name' => 'Fahim Kato'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.account_number.0', 'This is an MTN number. Choose MTN, or enter the other number.');
});

it('holds a number in someone else\'s name for the Admin', function () {
    addDestination(['method' => 'Mobile Money', 'network' => 'Airtel', 'account_number' => '0701234567', 'account_name' => 'Peter Okello'])
        ->assertCreated()->assertJsonPath('data.status', 'Awaiting review');

    Notification::assertSentTo($this->admin, PayoutDestinationNeedsReview::class);
});

it('checks a bank account with MarzPay and trusts the bank\'s name over the typed one', function () {
    Http::fake(['*/bank-transfer/validate' => Http::response(['status' => 'success', 'data' => [
        'account_number' => '60001256421', 'bank_name' => 'Equity Bank', 'bank_code' => 'EQU',
        'account_name' => 'HOME NEST LIMITED', 'is_registered' => 'ACTIVE', 'valid' => true, 'status_code' => 0,
    ]])]);

    addDestination(['method' => 'Bank', 'bank_name' => 'Equity Bank', 'account_number' => '6000 1256 421', 'account_name' => 'Someone typed this'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'Verified')
        ->assertJsonPath('data.checked_name', 'HOME NEST LIMITED')
        ->assertJsonPath('data.bank_code', 'EQU');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('key:secret'))
        && $request['account_number'] === '60001256421');
});

it('refuses a bank account the bank does not know', function () {
    Http::fake(['*/bank-transfer/validate' => Http::response(['status' => 'error', 'message' => 'Bank account validation failed', 'error_code' => 'VALIDATION_FAILED',
        'data' => ['bank_code' => 'EQU', 'status_code' => 30, 'status_description' => 'ACCOUNT NOT FOUND']], 400)]);

    addDestination(['method' => 'Bank', 'bank_name' => 'Equity Bank', 'account_number' => '123', 'account_name' => 'Home Nest Ltd'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.account_number.0', 'Equity Bank does not recognise this account (ACCOUNT NOT FOUND). Check the number and the bank.');
    expect(PayoutDestination::query()->count())->toBe(0);
});

it('saves a bank account for the Admin when MarzPay cannot answer', function () {
    Http::fake(['*/bank-transfer/validate' => Http::response('Gateway timeout', 504)]);

    addDestination(['method' => 'Bank', 'bank_name' => 'Equity Bank', 'account_number' => '60001256421', 'account_name' => 'Home Nest Ltd'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'Awaiting review')
        ->assertJsonPath('data.status_reason', 'The bank could not be asked just now, so the Admin will check this account.');
});

it('lets reviewers decide on a waiting destination once, and keeps one a shop is paid to', function () {
    $waiting = PayoutDestination::factory()->for($this->vendor)->awaitingReview()->create();
    Shop::factory()->for($this->vendor)->create(['payout_destination_id' => $waiting->id]);

    $this->postJson("/api/v1/payout-destinations/{$waiting->id}/approve")->assertForbidden();
    $this->deleteJson("/api/v1/payout-destinations/{$waiting->id}")->assertStatus(409);

    $this->actingAs($this->admin, 'sanctum');
    $this->postJson("/api/v1/payout-destinations/{$waiting->id}/approve")->assertOk()->assertJsonPath('data.status', 'Verified');
    $this->postJson("/api/v1/payout-destinations/{$waiting->id}/reject", ['reason' => 'Changed my mind.'])->assertUnprocessable();
    expect($waiting->fresh()->status)->toBe(PayoutDestinationStatus::Verified);
});

it('lets only an Owner choose where a shop is paid, and never a refused destination', function () {
    $shop = Shop::factory()->for($this->vendor)->create();
    $refused = PayoutDestination::factory()->for($this->vendor)->create(['status' => PayoutDestinationStatus::Rejected]);
    $foreign = PayoutDestination::factory()->create();

    $this->putJson("/api/v1/shops/{$shop->id}", ['payout_destination_id' => $refused->id])->assertUnprocessable();
    $this->putJson("/api/v1/shops/{$shop->id}", ['payout_destination_id' => $foreign->id])->assertUnprocessable();

    $staff = User::factory()->create();
    $staff->assignRole('Vendor');
    $this->vendor->members()->attach($staff->id, ['role' => 'Staff']);
    $staff->staffShops()->attach($shop->id);
    $mine = PayoutDestination::factory()->for($this->vendor)->create();
    $this->actingAs($staff, 'sanctum');
    $this->putJson("/api/v1/shops/{$shop->id}", ['payout_destination_id' => $mine->id])->assertForbidden();
});

it('matches names the way banks print them', function (string $account, bool $matches) {
    expect(NameMatch::belongsToAny($account, ['Home Nest Ltd', 'Fahim Kato']))->toBe($matches);
})->with([
    ['HOME NEST LIMITED', true],
    ['KATO FAHIM', true],
    ['Fahim A. Kato', true],
    ['FAHIM', false],
    ['Home Depot Ltd', false],
    ['Peter Okello', false],
]);
