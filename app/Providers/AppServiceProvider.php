<?php

namespace App\Providers;

use App\Support\BuyerRateLimits;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardAgainstLazyLoading();
        $this->shareBrandingWithEmails();
        // Per person, not per IP: buyers on one mobile network share a few addresses (docs/rate-limits.md).
        BuyerRateLimits::register();

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });
    }

    /**
     * Give every email view the two facts it needs about this installation.
     *
     * ## Why the views stopped calling `config()` themselves
     *
     * A view reaching into config is the thing Laravel's own guidance tells you
     * not to do: presentation should render what it is handed, and the sender
     * should decide what that is. One binding here means one place to change
     * if the platform is ever renamed, and a way to render a template under a
     * different brand for a test.
     *
     * ## Why a composer and not an argument at each call site
     *
     * Passing them per send is the letter of the guidance and the wrong shape
     * for a value identical in every message: five senders each pass the same
     * two keys and the sixth forgets. A composer bound to the `emails.*` family
     * supplies them once and cannot be forgotten, which is what composers are
     * for. Anything a MESSAGE knows (the recipient, the password, the
     * notification body) is still passed by its sender, because that genuinely
     * differs per send.
     *
     * ## The frontend URL is not `app.url`
     *
     * `app.url` is this API, on its own host. Every sign-in button in every
     * template would otherwise send people to a server that answers JSON at
     * them. See `config/app.php`.
     */
    private function shareBrandingWithEmails(): void
    {
        // The wildcard reaches nested views too, so `emails.users.*` and
        // `emails.password-reset.*` are both covered by this one binding.
        View::composer('emails.*', function ($view) {
            $view->with([
                'appName' => config('app.name'),
                'frontendUrl' => rtrim((string) config('app.frontend_url'), '/'),
                'supportEmail' => (string) config('mail.support_address'),
            ]);
        });
    }

    /**
     * Eloquent strict mode for lazy loading: the N+1 guard.
     *
     * `UserResource` exposes a `role` attribute backed by the `roles` relation, so
     * every user serialized without an eager-loaded `roles` fires its own query:
     * one list request quietly becomes one query per row. That is invisible at the
     * call site and easy to miss in review, so detection is automated instead of
     * left to discipline.
     *
     *   - dev, CI and tests -> throw, so the offending endpoint fails loudly and
     *                          the missing `with()` gets added
     *   - production        -> log only, so a missed eager-load degrades
     *                          performance rather than 500-ing a real request
     *
     * See docs/n-plus-one-guard.md.
     */
    private function guardAgainstLazyLoading(): void
    {
        Model::preventLazyLoading();

        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            if (! $this->app->isProduction()) {
                throw new LazyLoadingViolationException($model, $relation);
            }

            Log::warning('Lazy loading violation (N+1 risk)', [
                'model' => $model::class,
                'relation' => $relation,
            ]);
        });
    }
}
