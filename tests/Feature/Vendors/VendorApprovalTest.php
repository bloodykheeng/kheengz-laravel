<?php

/**
 * A vendor's papers and the Admin's decisions on it (D35). Suspending a vendor stops all its shops
 * selling without touching the shops' own statuses.
 */

use App\Enums\ShopStatus;
use App\Enums\VendorStatus;
use App\Jobs\SendSmsJob;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\VendorApplied;
use App\Notifications\VendorDecided;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    Queue::fake();
});

/** A Pending vendor and its Owner. @return array{0: Vendor, 1: User} */
function pendingVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::factory()->ownedBy($owner)->create();

    return [$vendor, $owner];
}

function uploadPaper(Vendor $vendor, string $type): TestResponse
{
    return test()->postJson("/api/v1/vendors/{$vendor->id}/documents", [
        'type' => $type,
        'file' => UploadedFile::fake()->image('id.jpg', 800, 500),
    ]);
}

it('takes the papers privately, replaces one of the same type, and tells the Admins once they are complete', function () {
    [$vendor, $owner] = pendingVendor();
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $this->actingAs($owner, 'sanctum');

    uploadPaper($vendor, 'National ID (front)')->assertCreated();
    Notification::assertNothingSent();

    uploadPaper($vendor, 'National ID (front)')->assertCreated();
    expect($vendor->documents()->count())->toBe(1);

    uploadPaper($vendor, 'National ID (back)')->assertCreated();
    Notification::assertSentTo($admin, VendorApplied::class);

    $path = $vendor->documents()->where('type', 'National ID (back)')->value('file_path');
    Storage::disk('local')->assertExists($path);
    expect($path)->toStartWith("vendor-documents/{$vendor->id}/");
});

it('shows a paper to its Owner and to reviewers, and to nobody else', function () {
    [$vendor, $owner] = pendingVendor();
    $this->actingAs($owner, 'sanctum');
    uploadPaper($vendor, 'National ID (front)');
    $document = $vendor->documents()->sole();

    $this->get("/api/v1/vendor-documents/{$document->id}/file")->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    [, $stranger] = pendingVendor();
    $this->actingAs($stranger, 'sanctum');
    $this->get("/api/v1/vendor-documents/{$document->id}/file")->assertNotFound();

    actingAsAdmin();
    $this->get("/api/v1/vendor-documents/{$document->id}/file")->assertOk();
});

it('refuses to approve a vendor whose papers are missing', function () {
    [$vendor] = pendingVendor();
    actingAsAdmin();

    $this->postJson("/api/v1/vendors/{$vendor->id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Still missing: National ID (front), National ID (back).');
});

it('approves a vendor with its papers, tells the Owner and texts the vendor, and decides only once', function () {
    [$vendor, $owner] = pendingVendor();
    $this->actingAs($owner, 'sanctum');
    uploadPaper($vendor, 'National ID (front)');
    uploadPaper($vendor, 'National ID (back)');

    actingAsAdmin();
    $this->postJson("/api/v1/vendors/{$vendor->id}/approve")->assertOk()->assertJsonPath('data.status', 'Approved');

    expect($vendor->fresh()->status)->toBe(VendorStatus::Approved)->and($vendor->fresh()->approved_at)->not->toBeNull();
    Notification::assertSentTo($owner, VendorDecided::class);
    Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->phone === $vendor->phone);

    // Pressed again (another reviewer's screen): already decided.
    $this->postJson("/api/v1/vendors/{$vendor->id}/approve")->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('needs a reason to reject or suspend, and suspending stops only selling, not the shops themselves', function () {
    $vendor = Vendor::factory()->approved()->ownedBy()->create();
    $shops = Shop::factory()->count(2)->active()->for($vendor)->create();
    actingAsAdmin();

    $this->postJson("/api/v1/vendors/{$vendor->id}/suspend")->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/vendors/{$vendor->id}/suspend", ['reason' => 'Buyers report fake goods.'])->assertOk();

    expect($shops->every(fn (Shop $s) => $s->fresh()->status === ShopStatus::Active))->toBeTrue()
        ->and($shops->contains(fn (Shop $s) => $s->fresh()->isSelling()))->toBeFalse();

    $this->postJson("/api/v1/vendors/{$vendor->id}/reinstate")->assertOk();
    expect($shops->every(fn (Shop $s) => $s->fresh()->isSelling()))->toBeTrue();
});

it('lets only reviewers decide', function () {
    [$vendor, $owner] = pendingVendor();
    $this->actingAs($owner, 'sanctum');
    $this->postJson("/api/v1/vendors/{$vendor->id}/approve")->assertForbidden();

    actingAsRole('Staff');
    $this->getJson("/api/v1/vendors/{$vendor->id}")->assertOk();
    $this->postJson("/api/v1/vendors/{$vendor->id}/approve")->assertForbidden();
});

it('keeps an approved vendor\'s checked identity, and its papers', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::factory()->approved()->ownedBy($owner)->create();
    $this->actingAs($owner, 'sanctum');
    uploadPaper($vendor, 'National ID (front)');

    $this->putJson("/api/v1/vendors/{$vendor->id}", ['address' => 'Ntinda, Kampala'])->assertOk();
    $this->putJson("/api/v1/vendors/{$vendor->id}", ['name' => 'Another Name'])->assertUnprocessable();
    $this->deleteJson('/api/v1/vendor-documents/'.$vendor->documents()->value('id'))->assertUnprocessable();
});

it('shows a vendor\'s people only their own vendors', function () {
    [$mine, $owner] = pendingVendor();
    [$theirs] = pendingVendor();
    $this->actingAs($owner, 'sanctum');

    $this->getJson('/api/v1/vendors')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    $this->getJson("/api/v1/vendors/{$theirs->id}")->assertForbidden();
});
