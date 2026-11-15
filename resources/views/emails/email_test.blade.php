{{-- A test send from EmailTestController, to prove the mail settings work. --}}
@component('emails.partials.shell', [
    'heading' => 'Test email',
    'preheader' => "If you can read this, {$appName} can send email.",
])
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 20px;">
        <tr>
            <td style="border-left:3px solid #0E5E45;padding:2px 0 2px 16px;font-size:15px;line-height:1.65;color:#374151;">
                {!! nl2br(e($messageBody)) !!}
            </td>
        </tr>
    </table>

    <p style="margin:0;font-size:13px;color:#6b7280;">
        If you can read this, {{ $appName }} can send email. Sent {{ now()->format('D, d M Y H:i:s T') }} for {{ $frontendUrl }}.
    </p>
@endcomponent
