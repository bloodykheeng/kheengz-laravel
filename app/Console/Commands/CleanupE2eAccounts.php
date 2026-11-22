<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vendor;
use App\Services\ProductCatalogue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the throwaway accounts the browser suite creates (`e2e-…@example.com`), and the vendors
 * that only they run (with their shops and everything in them, payout destinations, papers and
 * memberships), so Users and Vendors are not cluttered by test runs. Playwright's global teardown
 * runs it after every suite (kheengz-nextjs/e2e/global-teardown.ts).
 * Never in production: there it refuses and deletes nothing.
 */
#[Signature('e2e:cleanup')]
#[Description('Delete the throwaway accounts and vendors the browser suite creates (local and testing only)')]
class CleanupE2eAccounts extends Command
{
    public const PATTERN = 'e2e-%@example.com';

    public function handle(ProductCatalogue $catalogue): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: e2e:cleanup never runs in production.');

            return self::FAILURE;
        }

        $users = User::query()->where('email', 'like', self::PATTERN)->get();
        $ids = $users->pluck('id');

        // Vendors whose every member is a throwaway account: nobody real works there.
        $vendors = Vendor::query()
            ->whereHas('members', fn ($m) => $m->whereIn('users.id', $ids))
            ->whereDoesntHave('members', fn ($m) => $m->whereNotIn('users.id', $ids))
            ->with(['shops', 'documents'])
            ->get();

        DB::transaction(function () use ($users, $vendors, $catalogue) {
            foreach ($vendors as $vendor) {
                foreach ($vendor->shops as $shop) {
                    $shop->products()->get()->each(fn ($product) => $catalogue->delete($product));
                    $shop->sections()->get()->each->delete(); // each, so the photos go too
                    $shop->categoryRequests()->delete();
                    $shop->staff()->detach();
                    $shop->locations()->delete();
                    $shop->reviews()->delete();
                    $shop->delete();
                }
                $vendor->payoutDestinations()->delete();
                $vendor->documents->each->delete(); // each, so the files go too
                $vendor->members()->detach();
                $vendor->delete();
            }

            foreach ($users as $user) {
                $user->staffShops()->detach();
                $user->vendors()->detach();
                $user->delete();
            }
        });

        $this->info("Deleted {$vendors->count()} throwaway vendor(s) and {$users->count()} account(s).");

        return self::SUCCESS;
    }
}
