<?php

/**
 * Every email speaks as the installation (config('app.name')), never as GiftNest or SDS, in
 * the Royal colours, with the support address; a reset code reaches the account by email and SMS.
 */

use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => config(['app.name' => 'Kheengz', 'services.sms.driver' => 'log', 'mail.support_address' => 'hello@kheengz.com']));

it('renders every email as the installation, with the support address and none of the old wording', function () {
    $user = User::factory()->make(['name' => 'Fahim', 'email' => 'fahim@example.com', 'username' => null]);

    $views = [
        'emails.password-reset.password_reset_otp' => ['user' => $user, 'otp' => '482913', 'minutes' => 10],
        'emails.password-reset.password_reset_confirmation' => ['user' => $user],
        'emails.users.user-account-created' => ['user' => $user, 'plainPassword' => 'secret-123'],
        'emails.users.user-account-updated' => ['user' => $user, 'password_changed' => true],
        'emails.users.verify-email' => ['user' => $user, 'code' => '551203', 'minutes' => 10],
        'emails.notifications.user-notification' => ['user' => $user, 'notificationTitle' => 'Hello', 'notificationBody' => 'A notice.', 'notificationLink' => '/dashboard'],
        'emails.email_test' => ['messageBody' => 'Testing.'],
    ];

    foreach ($views as $view => $data) {
        $html = view($view, $data)->render();

        // toContain() reads every argument as a needle, so the checks carry their messages here.
        expect(str_contains($html, 'Kheengz'))->toBeTrue("{$view} does not name the installation")
            ->and(str_contains($html, '#0E5E45'))->toBeTrue("{$view} is not in the Royal colours")
            ->and(str_contains($html, 'hello@kheengz.com'))->toBeTrue("{$view} has no support address")
            ->and((bool) preg_match('/GiftNest|New Wave|gift|wedding|Service Delivery|inspection|government/i', $html))->toBeFalse("{$view} still carries old wording");
    }

    expect(view('emails.password-reset.password_reset_otp', $views['emails.password-reset.password_reset_otp'])->render())
        ->toContain('482913')->toContain('10 minutes');
});

it('names whoever bought the software, not Kheengz, when the installation is renamed', function () {
    config(['app.name' => 'Home Nest']);
    $user = User::factory()->make(['name' => 'Fahim', 'email' => 'fahim@example.com']);

    $html = view('emails.users.user-account-created', ['user' => $user, 'plainPassword' => 'x'])->render();

    expect($html)->toContain('Home Nest')->not->toContain('Kheengz');
});

it('sends a reset code by email and by SMS, and says where it went', function () {
    Mail::fake();
    User::factory()->create(['email' => 'fahim@example.com', 'phone' => '256772123456']);

    $this->postJson('/api/v1/auth/otp/request', ['email' => 'fahim@example.com'])
        ->assertOk()
        ->assertJsonPath('data.email_sent', true)
        ->assertJsonPath('data.sms_sent', true)
        ->assertJsonPath('message', 'A reset code has been sent to your email and phone. It works for 10 minutes.');
});
