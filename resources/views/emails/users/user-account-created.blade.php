{{--
    A new account, with the credentials to sign in with.

    Restyled onto `emails.partials.shell` and NOT rewritten: the content, the
    fields and the fact that the password travels in the message are all
    unchanged, because that was the decision. Every style is inline now, so the
    message keeps its shape in Outlook and behind the gateways that strip a
    `<style>` block.
--}}
@component('emails.partials.shell', [
    'heading' => "Your {$appName} account is ready",
    'preheader' => 'Your account has been created. Sign in and change your password.',
])
    <p style="margin:0 0 18px;font-size:15px;color:#374151;">
        Dear <strong style="color:#111827;">{{ $user->name }}</strong>,
    </p>

    <p style="margin:0 0 20px;font-size:15px;line-height:1.65;color:#374151;">
        Welcome to {{ $appName }}. Your account has been created and you can sign in with the
        details below. Please change your password after your first sign in.
    </p>

    {{-- The credentials, as a two-column table. Rows rather than a definition
         list, because Outlook collapses the margins a <dl> depends on. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
        style="margin:0 0 24px;border:1px solid #e5e7eb;border-radius:6px;">
        <tr>
            <td colspan="2"
                style="padding:12px 16px;background:#f4f6f9;border-bottom:1px solid #e5e7eb;font-family:'Segoe UI',Arial,sans-serif;font-size:12px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#6b7280;">
                Your sign in details
            </td>
        </tr>

        @php
            $label = "padding:10px 16px;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#6b7280;width:38%;border-bottom:1px solid #f0f2f5;";
            $value = "padding:10px 16px;font-family:'Segoe UI',Arial,sans-serif;font-size:14px;color:#111827;font-weight:600;border-bottom:1px solid #f0f2f5;";
        @endphp

        <tr>
            <td style="{{ $label }}">Full name</td>
            <td style="{{ $value }}">{{ $user->name }}</td>
        </tr>
        <tr>
            <td style="{{ $label }}">Email</td>
            <td style="{{ $value }}">{{ $user->email }}</td>
        </tr>
        @if ($user->username)
            <tr>
                <td style="{{ $label }}">Username</td>
                <td style="{{ $value }}">{{ $user->username }}</td>
            </tr>
        @endif
        <tr>
            <td style="{{ $label }}">Password</td>
            {{-- Monospace: a generated password contains characters that are
                 indistinguishable in a proportional face, and somebody has to
                 retype this one. --}}
            <td style="{{ $value }}font-family:Consolas,'Courier New',monospace;letter-spacing:.5px;">
                {{ $plainPassword }}
            </td>
        </tr>
        @if ($user->getRawOriginal('role') || $user->role !== 'No Role')
            <tr>
                <td style="{{ $label }}border-bottom:none;">Role</td>
                <td style="{{ $value }}border-bottom:none;">{{ $user->role }}</td>
            </tr>
        @endif
    </table>

    {{-- The warning, in amber rather than the banner blue, because it is the one
         part of this email that is not routine. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;">
        <tr>
            <td style="border-left:3px solid #C9A227;background:#fffbeb;padding:12px 16px;">
                <p
                    style="margin:0 0 4px;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;font-weight:700;color:#92400e;">
                    Keep this to yourself
                </p>
                <p
                    style="margin:0;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;line-height:1.6;color:#92400e;">
                    This message contains a temporary password. Sign in and change it, and do not share these
                    details with anybody.
                </p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
        <tr>
            <td align="center" style="border-radius:6px;background:#0E5E45;">
                <a href="{{ $frontendUrl }}/login"
                    style="display:inline-block;padding:12px 26px;font-family:'Segoe UI',Arial,sans-serif;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">
                    Sign in to {{ $appName }}
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
        Not expecting this, or think it reached you by mistake?
        @if (! empty($supportEmail))
            Write to <a href="mailto:{{ $supportEmail }}" style="color:#0E5E45;font-weight:600;">{{ $supportEmail }}</a> straight away.
        @else
            Tell the {{ $appName }} team straight away.
        @endif
    </p>
@endcomponent
