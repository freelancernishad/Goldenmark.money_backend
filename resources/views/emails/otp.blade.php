<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Your Verification Code - GoldenMark Money</title>
    <style>
        :root {
            color-scheme: light dark;
            supported-color-schemes: light dark;
        }
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
            text-align: center;
        }
        .otp-box-wrapper {
            text-align: center;
            margin: 28px 0;
        }
        .otp-card {
            display: inline-block;
            background-color: #fffbeb;
            background-image: linear-gradient(180deg, #fffbeb 0%, #fef3c7 100%);
            border: 2px dashed #f59e0b;
            border-radius: 16px;
            padding: 20px 36px;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.12);
        }
        .otp-code {
            font-family: 'Courier New', Courier, monospace;
            font-size: 38px;
            font-weight: 900;
            letter-spacing: 8px;
            color: #b45309;
            margin: 0;
            line-height: 1;
        }
        .otp-expiry {
            margin-top: 10px;
            font-size: 12px;
            font-weight: 700;
            color: #d97706;
        }
        .security-notice {
            background-color: #f8fafc;
            border-radius: 12px;
            padding: 16px 20px;
            border: 1px solid #e2e8f0;
            margin: 24px 0 20px 0;
        }
        .security-notice-title {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .security-notice-text {
            font-size: 13px;
            color: #64748b;
            line-height: 1.5;
            margin: 0;
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

        /* Dark Mode Override Styles */
        @media (prefers-color-scheme: dark) {
            body, table {
                background-color: #0b0f19 !important;
            }
            .email-container {
                background-color: #1e293b !important;
                border-color: #334155 !important;
            }
            .brand-header {
                background-color: #1e293b !important;
            }
            .brand-dark-text {
                color: #f8fafc !important;
            }
            .brand-badge {
                background-color: #78350f !important;
                color: #fef3c7 !important;
                border-color: #92400e !important;
            }
            .hero-title {
                color: #f8fafc !important;
            }
            .hero-subtitle {
                color: #cbd5e1 !important;
            }
            .otp-card {
                background: #2b1f0d !important;
                background-color: #2b1f0d !important;
                background-image: none !important;
                border-color: #f59e0b !important;
            }
            .otp-code {
                color: #fbbf24 !important;
            }
            .otp-expiry {
                color: #f59e0b !important;
            }
            .security-notice {
                background-color: #0f172a !important;
                border-color: #334155 !important;
            }
            .security-notice-title {
                color: #f8fafc !important;
            }
            .security-notice-text {
                color: #cbd5e1 !important;
            }
            .footer {
                background-color: #0f172a !important;
                border-top-color: #334155 !important;
                color: #94a3b8 !important;
            }
            .footer-title {
                color: #f8fafc !important;
            }
            .footer p {
                color: #94a3b8 !important;
            }
            .footer-links a {
                color: #f59e0b !important;
            }
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
                            <span class="brand-badge">Verification Code</span>
                        </div>
                    </div>

                    <!-- Content Body -->
                    <div class="content-body">
                        <h1 class="hero-title">Verify Your Identity</h1>
                        <p class="hero-subtitle">
                            Please enter the one-time password (OTP) below to authenticate your request and securely access your GoldenMark Money account.
                        </p>

                        <!-- OTP Code Card -->
                        <div class="otp-box-wrapper">
                            <div class="otp-card">
                                <div class="otp-code">{{ $otp }}</div>
                                <div class="otp-expiry">⏱️ Valid for 10 minutes</div>
                            </div>
                        </div>

                        <!-- Security Notice -->
                        <div class="security-notice">
                            <div class="security-notice-title">
                                🔒 Security Reminder
                            </div>
                            <p class="security-notice-text">
                                Never share this code with anyone. GoldenMark Money employees will never ask for your verification code. If you did not make this request, please contact our support team immediately.
                            </p>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="footer">
                        <p class="footer-title" style="margin: 0 0 10px 0; font-weight: 700; color: #0f172a;">GoldenMark Money™</p>
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
