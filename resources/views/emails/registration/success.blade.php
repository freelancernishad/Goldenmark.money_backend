<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to GoldenMark Money!</title>
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
        .features-card {
            background-color: #f8fafc;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 20px;
            margin: 24px 0;
        }
        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }
        .feature-item:last-child {
            margin-bottom: 0;
        }
        .feature-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background-color: #fef3c7;
            color: #b45309;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0;
        }
        .feature-text {
            font-size: 13.5px;
            color: #334155;
            line-height: 1.5;
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
                            <span class="brand-badge">Welcome Aboard</span>
                        </div>
                    </div>

                    <!-- Content Body -->
                    <div class="content-body">
                        <h1 class="hero-title">🎉 Welcome to GoldenMark Money, {{ $name }}!</h1>
                        <p class="hero-subtitle">
                            We're thrilled to welcome you. Your account has been registered successfully and is ready to use for all your payment and check mailing needs.
                        </p>

                        <!-- Highlights -->
                        <div class="features-card">
                            <div class="feature-item">
                                <div class="feature-icon">✉️</div>
                                <div class="feature-text">
                                    <strong>Postal Check Mailing ($0.50):</strong> Send physical checks via USPS & FedEx right from your dashboard.
                                </div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon">⚡</div>
                                <div class="feature-text">
                                    <strong>Instant eChecks ($0.11):</strong> Deliver instant digital checks via email to payees in seconds.
                                </div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon">🏦</div>
                                <div class="feature-text">
                                    <strong>Bank Integration:</strong> Connect multiple bank accounts securely and manage payees effortlessly.
                                </div>
                            </div>
                        </div>

                        <!-- CTA Button -->
                        <div class="btn-wrapper">
                            <a href="{{ config('app.url') }}/dashboard/home" class="btn-primary">Explore Your Dashboard</a>
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
