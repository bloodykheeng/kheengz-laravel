{{--
    An account was changed by a {{ $appName }} Admin.

    Restyled onto `emails.partials.shell`, content unchanged. The banner turns
    amber when the password moved, because that is the one version of this
    message the reader has to act on if it was not them.
--}}
@component('emails.partials.shell', [
    'heading' => $password_changed ? "Your {$appName} password was changed" : "Your {$appName} account was updated",
    'accent' => $password_changed ? '#b8821f' : '#0E5E45',
    'preheader' => $password_changed
        ? "Your password was changed. If this was not you, tell the {$appName} team now."
        : "A {$appName} Admin updated your account details.",
])
    <p style="margin:0 0 18px;font-size:15px;color:#374151;">
        Dear <strong style="color:#111827;">{{ $user->name }}</strong>,
    </p>

    <p style="margin:0 0 20px;font-size:15px;line-height:1.65;color:#374151;">
        A {{ $appName }} Admin has updated your account.
        @if ($password_changed)
            Your account details <strong>and your password</strong> were changed.
        @else
            Your account details were changed. Your password was not touched.
        @endif
    </p>

    @if ($password_changed)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;">
            <tr>
                <td style="border-left:3px solid #C9A227;background:#fffbeb;padding:12px 16px;">
                    <p
                        style="margin:0 0 4px;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;font-weight:700;color:#92400e;">
                        If this was not you
                    </p>
                    <p
                        style="margin:0;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;line-height:1.6;color:#92400e;">
                        Write to {{ $supportEmail ?: "the {$appName} team" }} straight away. Somebody else can sign in as you until
                        the password is changed again.
                    </p>
                </td>
            </tr>
        </table>
    @else
        <p style="margin:0 0 24px;font-size:14px;line-height:1.6;color:#6b7280;">
            If you were not expecting these changes, write to {{ $supportEmail ?: "the {$appName} team" }}.
        </p>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
        <tr>
            <td align="center" style="border-radius:6px;background:{{ $password_changed ? '#b8821f' : '#0E5E45' }};">
                <a href="{{ $frontendUrl }}/login"
                    style="display:inline-block;padding:12px 26px;font-family:'Segoe UI',Arial,sans-serif;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">
                    Sign in to {{ $appName }}
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
        This is an automated notification. The account it refers to is
        <strong style="color:#374151;">{{ $user->email }}</strong>.
    </p>
@endcomponent
