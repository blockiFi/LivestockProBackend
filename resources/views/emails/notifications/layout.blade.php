@php
    $priorityColors = [
        'critical' => ['bg' => '#7f1d1d', 'text' => '#ffffff', 'accent' => '#dc2626'],
        'high' => ['bg' => '#9a3412', 'text' => '#ffffff', 'accent' => '#ea580c'],
        'normal' => ['bg' => '#0f172a', 'text' => '#ffffff', 'accent' => '#0284c7'],
        'low' => ['bg' => '#334155', 'text' => '#ffffff', 'accent' => '#64748b'],
    ];
    $palette = $priorityColors[$priority ?? 'normal'] ?? $priorityColors['normal'];
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <title>{{ $notification_title }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f1f5f9; -webkit-font-smoothing: antialiased; }
        table { border-collapse: collapse; }
        img { border: 0; line-height: 100%; outline: none; text-decoration: none; }
        a { color: {{ $palette['accent'] }}; }
        .wrapper { width: 100%; background-color: #f1f5f9; padding: 24px 12px; }
        .card { width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12); }
        .px { padding-left: 32px; padding-right: 32px; }
        .heading { font-size: 20px; line-height: 28px; font-weight: 700; color: #0f172a; margin: 0 0 8px; }
        .text { font-size: 15px; line-height: 24px; color: #334155; margin: 0 0 16px; }
        .muted { font-size: 13px; line-height: 20px; color: #64748b; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
        .detail-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; padding: 10px 0 2px; }
        .detail-value { font-size: 15px; color: #0f172a; font-weight: 600; padding: 0 0 10px; border-bottom: 1px solid #e2e8f0; }
        .button { display: inline-block; background-color: {{ $palette['accent'] }}; color: #ffffff !important; font-size: 15px; font-weight: 600; text-decoration: none; padding: 13px 26px; border-radius: 10px; }
        .footer { font-size: 12px; line-height: 20px; color: #94a3b8; text-align: center; padding: 20px 24px 0; }
        @media only screen and (max-width: 480px) {
            .px { padding-left: 20px !important; padding-right: 20px !important; }
            .heading { font-size: 18px !important; line-height: 26px !important; }
            .button { display: block !important; text-align: center !important; }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <table role="presentation" class="card" cellpadding="0" cellspacing="0">
        <tr>
            <td style="background-color: {{ $palette['bg'] }}; padding: 24px 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="font-size: 15px; font-weight: 700; color: #ffffff;">
                            {{ $farm_name }}
                        </td>
                        <td align="right">
                            <span class="badge" style="background-color: rgba(255,255,255,0.18); color: #ffffff;">
                                {{ $priority_label }}
                            </span>
                        </td>
                    </tr>
                </table>
                <div style="margin-top: 6px; font-size: 12px; color: rgba(255,255,255,0.72);">
                    {{ $category_label }} &middot; {{ $type_label }}
                </div>
            </td>
        </tr>

        <tr>
            <td class="px" style="padding-top: 28px; padding-bottom: 4px;">
                <h1 class="heading">@yield('heading', $notification_title)</h1>
                <p class="text">@yield('intro', $notification_body)</p>
            </td>
        </tr>

        @hasSection('details')
            <tr>
                <td class="px" style="padding-bottom: 8px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                        @yield('details')
                    </table>
                </td>
            </tr>
        @endif

        @hasSection('extra')
            <tr>
                <td class="px" style="padding-top: 12px;">
                    @yield('extra')
                </td>
            </tr>
        @endif

        @if (!empty($action_url))
            <tr>
                <td class="px" style="padding-top: 24px; padding-bottom: 8px;">
                    <a href="{{ $action_url }}" class="button">{{ $action_label }}</a>
                </td>
            </tr>
        @endif

        <tr>
            <td class="px" style="padding-top: 20px; padding-bottom: 28px;">
                <p class="muted" style="margin: 0;">
                    Sent {{ $timestamp }} ({{ $timezone }}).
                </p>
            </td>
        </tr>
    </table>

    <div class="footer">
        Farm Central &middot; Smart Livestock Command Center<br>
        You are receiving this because of your notification preferences for {{ $farm_name }}.<br>
        <a href="{{ $app_url }}/dashboard/settings/notifications" style="color: #64748b;">Manage notification preferences</a>
    </div>
</div>
</body>
</html>
