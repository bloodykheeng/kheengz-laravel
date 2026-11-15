<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the throwaway accounts the browser suite creates (`e2e-…@example.com`), so Users is not
 * cluttered by test runs. Playwright's global teardown runs it after every suite
 * (kheengz-nextjs/e2e/global-teardown.ts).
 * Never in production: there it refuses and deletes nothing.
 */
#[Signature('e2e:cleanup')]
#[Description('Delete the throwaway accounts the browser suite creates (local and testing only)')]
class CleanupE2eAccounts extends Command
{
    public const PATTERN = 'e2e-%@example.com';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: e2e:cleanup never runs in production.');

            return self::FAILURE;
        }

        $users = User::query()->where('email', 'like', self::PATTERN)->get();

        DB::transaction(function () use ($users) {
            foreach ($users as $user) {
                $user->delete();
            }
        });

        $this->info("Deleted {$users->count()} throwaway account(s).");

        return self::SUCCESS;
    }
}
