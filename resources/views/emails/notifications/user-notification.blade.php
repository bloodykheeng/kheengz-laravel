{{--
    One notification, emailed.

    Rendered through `emails.partials.shell`, so every style here is inline and
    survives Outlook and the corporate gateways that strip a `<style>` block.
    See the shell for why.
--}}
@component('emails.partials.shell', [
    'heading' => $notificationTitle ?? "A notice from {$appName}",
    'preheader' => \Illuminate\Support\Str::limit(strip_tags($notificationBody ?? ''), 120),
])
    {{-- `$user` is set by SendEmailNotificationToUsersJob for each recipient. --}}
    <p style="margin:0 0 18px;font-size:15px;color:#374151;">
        Hello{!! ! empty($user->name) ? ' <strong style="color:#111827;">'.e($user->name).'</strong>' : '' !!},
    </p>

    {{-- The message itself, set off by a rule on its left rather than a heavy
         box: the body IS the email, and a panel implies it is one item inside
         something larger. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;">
        <tr>
            <td style="border-left:3px solid #0E5E45;padding:2px 0 2px 16px;">
                <p style="margin:0;font-size:16px;font-weight:600;color:#0E5E45;line-height:1.4;">
                    {{ $notificationTitle ?? 'You have a new notification' }}
                </p>
                <div style="margin:8px 0 0;font-size:15px;line-height:1.65;color:#374151;">
                    {{-- Escaped, never {!! !!} on the raw value: the body is typed
                         by an administrator into a plain textarea, so any markup
                         in it is either a mistake or an attempt. `nl2br` runs on
                         the ESCAPED string, which is why the outer echo is raw. --}}
                    {!! nl2br(e($notificationBody ?? '')) !!}
                </div>
            </td>
        </tr>
    </table>

    @if (!empty($notificationLink))
        @php
            // A relative link is stored (the dashboard's own route), so it is
            // joined to FRONTEND_URL here: an email cannot follow one.
            $href = \Illuminate\Support\Str::startsWith($notificationLink, ['http://', 'https://'])
                ? $notificationLink
                : $frontendUrl . '/' . ltrim($notificationLink, '/');
        @endphp

        {{-- A table-wrapped button, not a styled <a>: Outlook ignores padding on
             an inline element, which collapses the button to bare blue text. --}}
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
            <tr>
                <td align="center" style="border-radius:6px;background:#0E5E45;">
                    <a href="{{ $href }}"
                        style="display:inline-block;padding:12px 26px;font-family:'Segoe UI',Arial,sans-serif;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">
                        Open in {{ $appName }}
                    </a>
                </td>
            </tr>
        </table>
    @endif

    {{-- `$reason` says why this reached them: an account holder by default. --}}
    <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
        {{ $reason ?? "You are receiving this because you have a {$appName} account." }}
    </p>
@endcomponent
