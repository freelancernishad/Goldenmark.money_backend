<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Check #{{ $payment->check_number }}</title>
    <style>
        @page { margin: 0; size: letter; } /* Standard Letter Size */
        body { font-family: 'Helvetica', sans-serif; font-size: 12px; margin: 0; padding: 0; }
        
        .check-container {
            width: 100%;
            height: 3.5in; /* Standard Check Height */
            position: relative;
            background: #fff;
            /* background-image: url('path/to/check-bg.png'); */ /* Optional background */
            background-size: contain;
            border-bottom: 1px dashed #ccc;
        }

        .absolute { position: absolute; }
        .bold { font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .micr-font {
            font-family: 'MICR Encoding', monospace; /* Use a webfont if available, or fallback */
            font-size: 20px;
            letter-spacing: 2px;
        }

        /* Check Number (Top Right) */
        .check-number { top: 0.3in; right: 0.5in; font-size: 16px; font-weight: bold; }

        /* Payer Info (Top Left) */
        .payer-info { top: 0.3in; left: 0.5in; width: 3in; }
        .payer-name { font-weight: bold; font-size: 14px; margin-bottom: 2px; }
        .payer-address { font-size: 11px; line-height: 1.2; }

        /* Date (Right) */
        .date-label { top: 0.8in; right: 2in; font-size: 10px; color: #555; }
        .date-value { top: 0.75in; right: 0.5in; width: 1.4in; border-bottom: 1px solid #000; padding: 2px 5px; text-align: center; }

        /* Pay To (Center) */
        .pay-to-label { top: 1.3in; left: 0.5in; font-size: 10px; color: #555; width: 0.8in; }
        .pay-to-value { top: 1.25in; left: 1.3in; width: 4.5in; border-bottom: 1px solid #000; font-size: 14px; font-weight: bold; padding: 2px 5px; }

        /* Amount (Right) */
        .amount-box { top: 1.25in; right: 0.5in; width: 1.4in; border: 1px solid #ddd; background: #f9f9f9; padding: 5px; text-align: right; font-weight: bold; font-size: 14px; }
        .currency-symbol { float: left; color: #777; font-size: 12px; margin-top: 2px; }

        /* Amount Text (Center below Pay To) */
        .amount-text-value { top: 1.6in; left: 0.5in; width: 6.8in; border-bottom: 1px solid #000; font-size: 12px; padding: 2px 5px; background: #fff; }
        .dollars-label { position: absolute; right: 0; top: 0; font-size: 10px; color: #555; padding-right: 5px; line-height: 20px; }

        /* Memo (Bottom Left) */
        .memo-label { top: 2.2in; left: 0.5in; font-size: 10px; color: #555; }
        .memo-value { top: 2.15in; left: 1in; width: 2.5in; border-bottom: 1px solid #000; padding: 2px 5px; font-size: 11px; }

        /* Signature (Bottom Right) */
        .signature-line { top: 2.15in; right: 0.5in; width: 2.5in; border-bottom: 1px solid #000; text-align: center; }
        .signature-label { top: 2.35in; right: 0.5in; width: 2.5in; text-align: center; font-size: 10px; color: #555; }
        .signature-img { height: 40px; }

        /* Bank Info (Center/Bottom) */
        .bank-info { top: 0.3in; left: 50%; transform: translateX(-50%); text-align: center; width: 2.5in; }
        .bank-name { font-weight: bold; font-size: 12px; }
        .bank-address { font-size: 10px; color: #555; }

        /* MICR Line (Bottom) */
        .micr-line {
            bottom: 0.3in;
            left: 0.5in;
            width: 7.5in;
            text-align: center;
            font-family: 'Helvetica', monospace; /* Fallback since webfonts are tricky in dompdf */
            font-size: 18px;
            letter-spacing: 3px;
        }

        /* Stub (Bottom) */
        .stub-container {
            margin-top: 0.5in;
            padding: 0.5in;
            border-top: 1px dashed #ccc;
        }
        .stub-table { width: 100%; border-collapse: collapse; }
        .stub-table th, .stub-table td { border-bottom: 1px solid #eee; padding: 5px; text-align: left; }
        .stub-table th { font-weight: bold; color: #555; font-size: 11px; }
    </style>
</head>
<body>

    <!-- Check Top -->
    <div class="check-container">
        <!-- Payer -->
        <div class="absolute payer-info">
            @if(!empty($payment->company_name))
            <div class="payer-name">{{ $payment->company_name }}</div>
            @endif
            @php
                $displayCompanyAddress = !empty($payment->company_address)
                    ? trim(preg_replace('/^(?:U\.?S\.?A\.?|U\.?S\.?|United States(?: of America)?)$/i', '', preg_replace('/(?:,\s*|\s+)(?:U\.?S\.?A\.?|U\.?S\.?|United States(?: of America)?)\s*$/i', '', $payment->company_address)))
                    : '';
            @endphp
            @if(!empty($displayCompanyAddress))
            <div class="payer-address">{{ $displayCompanyAddress }}</div>
            @endif
        </div>

        <!-- Check Number -->
        <div class="absolute check-number">{{ $payment->check_number }}</div>

        <!-- Date -->
        @if(empty($options['withoutDate']) && !empty($payment->issue_date))
        <div class="absolute date-label">Date</div>
        <div class="absolute date-value">{{ \Carbon\Carbon::parse($payment->issue_date)->format('m/d/Y') }}</div>
        @endif

        <!-- Pay To -->
        @if(empty($options['withoutPayee']))
        <div class="absolute pay-to-label">Pay to the<br>Order of</div>
        <div class="absolute pay-to-value">{{ $payment->payee_name ?? 'Unknown Payee' }}</div>
        @endif

        <!-- Amount Box -->
        @if(empty($options['withoutAmount']))
        <div class="absolute amount-box">
            <span class="currency-symbol">$</span>
            {{ number_format($payment->amount, 2) }}
        </div>

        <!-- Amount Text -->
        <div class="absolute amount-text-value">
            {{ $amountInWords }}
            <span class="dollars-label">Dollars</span>
        </div>
        @endif

        <!-- Bank Info -->
        <div class="absolute bank-info">
            <div class="bank-name">{{ $payment->bank->official_name ?? $payment->bank->account_nick_name ?? 'Bank Name' }}</div>
            <div class="bank-address">{{ $payment->bank->formatted_address ?? '' }}</div>
        </div>

        <!-- Memo -->
        <div class="absolute memo-label">Memo</div>
        <div class="absolute memo-value">{{ $payment->memo ?? 'Payment' }}</div>

        <!-- Signature -->
        @if(empty($options['withoutSign']))
        <div class="absolute signature-line">
            @if($payment->signature_image && $payment->signature_image !== 'NO_SIGNATURE')
                <img src="{{ $payment->signature_image }}" class="signature-img">
            @else
                <div style="height: 30px;"></div> <!-- Space for wet signature -->
            @endif
        </div>
        <div class="absolute signature-label">Authorized Signature</div>
        @endif

        <!-- MICR Line -->
        <div class="absolute micr-line">
            C{{ $payment->check_number }}C A{{ $payment->bank->routing_number }}A {{ $payment->bank->account_number }}C
        </div>
    </div>

    <!-- Stub -->
    <div class="stub-container">
        <h3 style="margin-top: 0;">Payment Advice</h3>
        <table class="stub-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Ref / Check #</th>
                    <th>To</th>
                    <th>Memo</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $payment->created_at->format('m/d/Y') }}</td>
                    <td>{{ $payment->check_number }}</td>
                    <td>{{ $payment->payee_name }}</td>
                    <td>{{ $payment->memo }}</td>
                    <td class="text-right">${{ number_format($payment->amount, 2) }}</td>
                </tr>
            </tbody>
        </table>

        @if(!empty($payment->company_name))
        <div style="margin-top: 20px; font-size: 11px; color: #777;">
            <strong>Issued by:</strong> {{ $payment->company_name }}
        </div>
        @endif
    </div>

</body>
</html>
