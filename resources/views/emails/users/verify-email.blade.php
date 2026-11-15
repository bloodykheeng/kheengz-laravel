{{--
    The sign-up code. Sent by App\Services\EmailVerification with `$user`, `$code` and `$minutes`
    (from the service, so the email can never disagree with the cache).
--}}
@component('emails.partials.shell', [
    'heading' => 'Confirm your email',
    'preheader' => "Your {$appName} code is {$code}. It works for {$minutes} minutes.",
])
    <p style="margin:0 0 16px;">Hello <strong style="color:#111827;">{{ $user->name }}</strong>,</p>

    <p style="margin:0 0 24px;">
        Enter this code in {{ $appName }} to confirm that <strong style="color:#111827;">{{ $user->email }}</strong> is yours and finish creating your account.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;">
        <tr>
            <td align="center" style="background:#f1f7f3;border:2px dashed #0E5E45;border-radius:10px;padding:24px;">
                <p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#6b7280;">
                    Your code
                </p>
                <p style="margin:0;font-family:Consolas,'Courier New',monospace;font-size:38px;font-weight:700;letter-spacing:10px;color:#0E5E45;">
                    {{ $code }}
                </p>
                <p style="margin:12px 0 0;font-size:13px;color:#6b7280;">
                    It works for <strong>{{ $minutes }} minutes</strong>, once.
                </p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 8px;">
        <tr>
            <td style="border-left:3px solid #C9A227;background:#fffbeb;padding:12px 16px;">
                <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#92400e;">Did not sign up?</p>
                <p style="margin:0;font-size:13px;line-height:1.6;color:#92400e;">
                    Ignore this email: no account is made without the code. Never share it; {{ $appName }} will never ask you for it.
                </p>
            </td>
        </tr>
    </table>
@endcomponent
