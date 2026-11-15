{{--
    Sent once the password has been reset with a code. Amber, because the one
    reader who must act on it is the one for whom it was not them.
--}}
@component('emails.partials.shell', [
    'heading' => 'Your password was changed',
    'accent' => '#b8821f',
    'preheader' => "Your {$appName} password was just reset. If this was not you, act now.",
])
    <p style="margin:0 0 16px;">Hello <strong style="color:#111827;">{{ $user->name }}</strong>,</p>

    <p style="margin:0 0 24px;">
        The password on your {{ $appName }} account ({{ $user->email }}) was just reset with a code. You can sign in with the new one now.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
        <tr>
            <td align="center" style="border-radius:8px;background:#0E5E45;">
                <a href="{{ $frontendUrl }}/login"
                    style="display:inline-block;padding:12px 26px;font-family:'Segoe UI',Arial,sans-serif;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
                    Sign in to {{ $appName }}
                </a>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td style="border-left:3px solid #C9A227;background:#fffbeb;padding:12px 16px;">
                <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#92400e;">If this was not you</p>
                <p style="margin:0;font-size:13px;line-height:1.6;color:#92400e;">
                    Reset your password again straight away from the sign-in page
                    @if (! empty($supportEmail))
                        and write to <a href="mailto:{{ $supportEmail }}" style="color:#92400e;font-weight:700;">{{ $supportEmail }}</a>
                    @endif
                    so we can check your account.
                </p>
            </td>
        </tr>
    </table>
@endcomponent
