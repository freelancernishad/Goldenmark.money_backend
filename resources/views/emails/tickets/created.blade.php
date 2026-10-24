<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Ticket Received - GoldenMark Money</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #334155;
            -webkit-text-size-adjust: 100%;
        }
        table {
            border-collapse: collapse;
        }
        .email-container {
            max-width: 600px;
            margin: 30px auto;
            background-color: #ffffff;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.03);
        }
        .header-top-bar {
            height: 5px;
            background: linear-gradient(90deg, #f59e0b 0%, #ea580c 100%);
        }
        .brand-header {
            padding: 32px 36px 20px 36px;
            background: #ffffff;
            text-align: center;
        }
        .brand-logo-link {
            text-decoration: none;
            display: inline-block;
        }
        .brand-logo-img {
            max-height: 48px;
            width: auto;
            max-width: 220px;
            height: auto;
            display: block;
            margin: 0 auto;
            border: 0;
            outline: none;
            text-decoration: none;
        }
        .brand-badge {
            display: inline-block;
            margin-top: 10px;
            padding: 4px 14px;
            background-color: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .content-body {
            padding: 10px 36px 36px 36px;
        }
        .hero-title {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 12px 0;
            text-align: center;
        }
        .hero-subtitle {
            font-size: 15px;
            line-height: 1.6;
            color: #475569;
            margin: 0 0 24px 0;
            text-align: left;
        }
        .ticket-card {
            background-color: #f8fafc;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 24px;
            margin: 24px 0;
        }
        .ticket-header {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .ticket-table {
            width: 100%;
        }
        .ticket-table td {
            padding: 8px 0;
            font-size: 14px;
            color: #475569;
        }
        .ticket-table .label {
            font-weight: 600;
            color: #64748b;
            width: 35%;
        }
        .ticket-table .value {
            text-align: right;
            font-weight: 700;
            color: #0f172a;
        }
        .badge-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
        }
        .badge-amber {
            background-color: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
        }
        .btn-wrapper {
            text-align: center;
            margin: 30px 0 10px 0;
        }
        .btn-primary {
            display: inline-block;
            background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);
            color: #ffffff !important;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            padding: 14px 34px;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
        }
        .footer {
            background-color: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 24px 36px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
        }
        .footer-links a {
            color: #d97706;
            text-decoration: none;
            font-weight: 600;
            margin: 0 6px;
        }
        .footer-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; padding: 20px 10px;">
        <tr>
            <td align="center">
                <div class="email-container">
                    <!-- Top Accent Bar -->
                    <div class="header-top-bar"></div>

                    <!-- Brand Header -->
                    <div class="brand-header">
                        <a href="{{ $frontendUrl ?? env('FRONTEND_URL', 'https://goldenmark.money') }}" target="_blank" class="brand-logo-link" style="text-decoration: none; display: inline-block;">
                            @if(isset($message) && file_exists(public_path('images/logo.png')))
                                <img src="{{ $message->embed(public_path('images/logo.png')) }}" alt="GoldenMark Money" class="brand-logo-img" style="max-height: 48px; width: auto; max-width: 220px; display: block; margin: 0 auto; border: 0;" />
                            @elseif(isset($message) && file_exists(public_path('logo.png')))
                                <img src="{{ $message->embed(public_path('logo.png')) }}" alt="GoldenMark Money" class="brand-logo-img" style="max-height: 48px; width: auto; max-width: 220px; display: block; margin: 0 auto; border: 0;" />
                            @else
                                <img src="{{ $logoUrl ?? (rtrim(env('FRONTEND_URL', 'https://goldenmark.money'), '/') . '/logo.png') }}" alt="GoldenMark Money" class="brand-logo-img" style="max-height: 48px; width: auto; max-width: 220px; display: block; margin: 0 auto; border: 0;" />
                            @endif
                        </a>
                        <div>
                            <span class="brand-badge">Support Desk</span>
                        </div>
                    </div>

                    <!-- Content Body -->
                    <div class="content-body">
                        <h1 class="hero-title">Support Ticket Received</h1>
                        <p class="hero-subtitle">
                            Hi <strong>{{ $user_name }}</strong>,<br><br>
                            We have received your support request. Our customer support team is actively reviewing your ticket and will respond shortly.
                        </p>

                        <!-- Ticket Summary Card -->
                        <div class="ticket-card">
                            <div class="ticket-header">Ticket Details</div>
                            <table class="ticket-table" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td class="label">Ticket Reference:</td>
                                    <td class="value"><span style="font-family: monospace; color: #d97706;">#{{ $ticket_id }}</span></td>
                                </tr>
                                <tr>
                                    <td class="label">Subject:</td>
                                    <td class="value">{{ $subject }}</td>
                                </tr>
                                <tr>
                                    <td class="label">Priority:</td>
                                    <td class="value">
                                        <span class="badge-pill badge-amber">{{ $priority }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="label">Current Status:</td>
                                    <td class="value">
                                        <span class="badge-pill badge-amber">{{ $status }}</span>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <p style="font-size: 13.5px; color: #64748b; line-height: 1.6; margin: 0 0 20px 0;">
                            You can track responses, upload additional details, and reply directly from your support center.
                        </p>

                        <!-- View Ticket Action Button -->
                        <div class="btn-wrapper">
                            <a href="{{ config('app.url') }}/user/support" class="btn-primary">View Support Ticket</a>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="footer">
                        <p style="margin: 0 0 10px 0; font-weight: 700; color: #0f172a;">GoldenMark Money™</p>
                        <p style="margin: 0 0 10px 0; color: #64748b;">
                            74-09 37th Avenue Suite 203B, Jackson Heights, NY 11372<br>
                            Tel: 833 711 4030 &bull; Email: support@goldenmark.money
                        </p>
                        <div class="footer-links" style="margin-bottom: 12px;">
                            <a href="{{ config('app.url') }}/privacy-policy">Privacy Policy</a> &bull;
                            <a href="{{ config('app.url') }}/terms">Terms of Service</a> &bull;
                            <a href="{{ config('app.url') }}/contact">Support</a>
                        </div>
                        <p style="margin: 0; font-size: 11px; color: #94a3b8;">
                            &copy; {{ date('Y') }} GoldenMark Money™. All rights reserved. Powered by ZSI.ai.
                        </p>
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
