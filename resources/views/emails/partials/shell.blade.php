{{--
    The one layout every email renders inside (from GiftNest).

    ## Why every style here is inline

    Email clients throw away a `<style>` block more often than not: Outlook.com
    strips head styles outright, several company mail gateways strip them, and
    Gmail drops them whenever it clips a long message. Inline styles survive all
    of it, which is why Alpha does the same.

    ## Why a table and not a div

    Outlook renders through Word, which supports almost no modern layout. A
    centred, fixed-width table is the only construction that holds its shape in
    every client. `border-radius` degrades harmlessly to square corners.

    ## Slots

    - `$heading`     the banner's main line, defaults to the app name
    - `$accent`      the banner and button colour, so a warning can differ
    - `$preheader`   the grey line a phone shows beside the subject
    - `$reference`   an optional reference printed in the footer
    - `$slot`        the message body

    `$appName`, `$frontendUrl` and `$supportEmail` are NOT slots: they come from
    the `emails.*` view composer in AppServiceProvider, because they are the same
    in every message and a template that reads config cannot be rendered under a
    test brand.

    Usage:

        @component('emails.partials.shell', ['heading' => 'Your account'])
            ...body...
        @endcomponent
--}}
@php
    $accent = $accent ?? '#0E5E45';
    $font = "font-family:'Segoe UI',Arial,sans-serif;";
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $heading ?? $appName }}</title>
</head>

<body style="margin:0;padding:0;background-color:#F6F7F5;">
    @isset($preheader)
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;height:0;width:0;">
            {{ $preheader }}
        </div>
    @endisset

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
        style="background-color:#F6F7F5;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"
                    style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #DDE3DF;">

                    {{-- Banner: the installation's name, then what this message is about. --}}
                    <tr>
                        <td align="center" style="background:{{ $accent }};padding:28px 32px;">
                            <p style="margin:0;{!! $font !!}font-size:15px;font-weight:600;letter-spacing:.4px;color:#ffffff;">
                                {{ $appName }}
                            </p>
                            <p style="margin:10px 0 0;{!! $font !!}font-size:21px;font-weight:600;line-height:1.35;color:#ffffff;">
                                {{ $heading ?? $appName }}
                            </p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px;{!! $font !!}font-size:15px;line-height:1.65;color:#374151;">
                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background:#F6F7F5;padding:20px 32px;text-align:center;border-top:1px solid #E3E9E5;{!! $font !!}font-size:12px;line-height:1.6;color:#5B6862;">
                            @if (! empty($supportEmail))
                                <p style="margin:0 0 6px;">
                                    Questions? Write to
                                    <a href="mailto:{{ $supportEmail }}" style="color:#0E5E45;text-decoration:none;font-weight:600;">{{ $supportEmail }}</a>
                                </p>
                            @endif
                            <p style="margin:0;">&copy; {{ date('Y') }} {{ $appName }}. Replies to this address are not read.</p>
                            @isset($reference)
                                <p style="margin:8px 0 0;font-size:11px;color:#9AA8A1;">
                                    Reference: {{ $reference }} &middot; Sent {{ now()->format('d M Y, H:i') }}
                                </p>
                            @endisset
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
