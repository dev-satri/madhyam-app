<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Invoice #{{ $invoice->id }} — {{ $agency->agency_name ?? 'Madhyam' }}</title>
    @php
    $brand = $brandColor ?? config('app.brand_color', '#4f46e5');
    $isPaid = $invoice->status === 'paid';
    $isOverdue = $invoice->status === 'overdue';
    $balanceColor = $isPaid ? '#059669' : ($isOverdue ? '#dc2626' : '#111827');
    $invoiceNumber = str_pad($invoice->id, 5, '0', STR_PAD_LEFT);
@endphp
    <style>
        @page {
            size: A4;
            margin: 20mm 22mm;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family:
                DejaVu Sans,
                Helvetica,
                Arial,
                sans-serif;
            font-size: 11px;
            color: #111827;
            line-height: 1.55;
            word-wrap: break-word;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        td,
        th {
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        /* ── Top rule (thin brand accent) ── */
        .top-rule {
            height: 3px;
            background: {{ $brand }};
            margin-bottom: 40px;
        }

        /* ── Header ── */
        .header td {
            vertical-align: top;
        }
        .agency-name {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
        }
        .agency-tag {
            font-size: 9.5px;
            color: #6b7280;
            margin-top: 4px;
            line-height: 1.7;
        }
        .doc-title {
            font-size: 24px;
            font-weight: 800;
            color: #111827;
            text-align: right;
            line-height: 1.1;
        }
        .doc-number {
            font-size: 10px;
            color: #6b7280;
            text-align: right;
            margin-top: 8px;
        }
        .doc-number strong {
            color: #111827;
            font-weight: 600;
        }

        /* ── Meta strip ── */
        .meta {
            margin-top: 40px;
            padding: 20px 0;
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
        }
        .meta td {
            width: 33.33%;
            padding-right: 16px;
        }
        .meta td:last-child {
            padding-right: 0;
        }
        .meta .m-label {
            font-size: 8px;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #9ca3af;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .meta .m-value {
            font-size: 12px;
            font-weight: 600;
            color: #111827;
        }
        .meta .m-value.overdue {
            color: #dc2626;
        }
        .meta .m-value.paid {
            color: #059669;
        }

        /* ── Parties (Billed to / From) ── */
        .parties {
            margin-top: 36px;
        }
        .parties td {
            width: 50%;
            padding-right: 24px;
        }
        .parties td:last-child {
            padding-right: 0;
        }
        .p-label {
            font-size: 8px;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #9ca3af;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .p-name {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }
        .p-line {
            font-size: 10px;
            color: #6b7280;
            line-height: 1.75;
        }

        /* ── Description ── */
        .description-block {
            margin-top: 36px;
            padding: 18px 20px;
            background: #f9fafb;
            border-radius: 6px;
            font-size: 10.5px;
            color: #4b5563;
            line-height: 1.7;
        }

        /* ── Line items table ── */
        .items {
            margin-top: 36px;
        }
        .items thead td {
            font-size: 8px;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #9ca3af;
            font-weight: 700;
            padding: 0 0 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .items tbody td {
            padding: 16px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 11px;
        }
        .items .item-title {
            font-weight: 600;
            color: #111827;
        }
        .items .item-sub {
            font-size: 10px;
            color: #6b7280;
            margin-top: 3px;
        }
        .items .amount {
            text-align: right;
            font-weight: 600;
            color: #111827;
            white-space: nowrap;
        }

        /* ── Totals ── */
        .totals-wrap {
            margin-top: 24px;
        }
        .totals-wrap td {
            vertical-align: top;
        }
        .totals {
            width: 100%;
        }
        .totals td {
            padding: 8px 0;
            font-size: 11px;
        }
        .totals .t-label {
            color: #6b7280;
            width: 55%;
        }
        .totals .t-value {
            text-align: right;
            font-weight: 600;
            color: #111827;
            white-space: nowrap;
            width: 45%;
        }
        .totals .t-value.discount {
            color: #dc2626;
        }
        .totals .t-value.paid {
            color: #059669;
        }
        .totals tr.divider td {
            border-top: 1px solid #e5e7eb;
            padding-top: 14px;
        }
        .totals tr.grand td {
            padding-top: 16px;
            border-top: 2px solid #111827;
            font-size: 14px;
        }
        .totals tr.grand .t-label {
            color: #111827;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 1px;
        }
        .totals tr.grand .t-value {
            font-size: 18px;
            font-weight: 800;
            color: {{ $balanceColor }};
        }

        /* ── Status pill (small, corner) ── */
        .status-line {
            margin-top: 14px;
            text-align: right;
        }
        .status-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .status-pill.paid {
            background: #d1fae5;
            color: #065f46;
        }
        .status-pill.pending {
            background: #fef3c7;
            color: #92400e;
        }
        .status-pill.overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        /* ── Section block (payments / installments) ── */
        .section {
            margin-top: 48px;
        }
        .section-label {
            font-size: 8px;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #9ca3af;
            font-weight: 700;
            margin-bottom: 14px;
        }

        /* ── Installment progress ── */
        .install-progress {
            padding: 18px 20px;
            background: #f9fafb;
            border-radius: 6px;
        }
        .install-caption {
            font-size: 10.5px;
            color: #4b5563;
            margin-bottom: 12px;
            line-height: 1.65;
        }
        .install-caption strong {
            color: #111827;
            font-weight: 700;
        }
        .install-bar {
            height: 6px;
            background: #e5e7eb;
            border-radius: 999px;
            overflow: hidden;
        }
        .install-bar-fill {
            height: 6px;
            background: {{ $brand }};
            border-radius: 999px;
        }

        /* ── Payments list ── */
        .payments td {
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 10.5px;
        }
        .payments tr:last-child td {
            border-bottom: 0;
        }
        .pay-date {
            color: #6b7280;
            width: 22%;
        }
        .pay-method {
            width: 22%;
            color: #4b5563;
        }
        .pay-note {
            color: #9ca3af;
            font-style: italic;
        }
        .pay-amt {
            font-weight: 700;
            color: #111827;
            text-align: right;
            white-space: nowrap;
            width: 18%;
        }
        .verified-tick {
            color: #059669;
            font-size: 9px;
            font-weight: 700;
            margin-left: 4px;
        }

        /* ── Footer ── */
        .footer {
            margin-top: 60px;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
            font-size: 9px;
            color: #9ca3af;
            line-height: 1.7;
            text-align: center;
        }
        .footer strong {
            color: #4b5563;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="top-rule"></div>

    {{-- ═══════════════════ HEADER ═══════════════════ --}}
    <table class="header" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <div class="agency-name">{{ $agency->agency_name ?? 'Madhyam' }}</div>
                @if ($agency->agency_email || $agency->agency_phone)
                    <div class="agency-tag">
                        @if ($agency->agency_email) {{ $agency->agency_email }}@endif
                        @if ($agency->agency_email && $agency->agency_phone) &nbsp;·&nbsp; @endif
                        @if ($agency->agency_phone) {{ $agency->agency_phone }}@endif
                    </div>
                @endif
            </td>
            <td>
                <div class="doc-title">Invoice</div>
                <div class="doc-number"><strong>#{{ $invoiceNumber }}</strong></div>
            </td>
        </tr>
    </table>

    {{-- ═══════════════════ META STRIP ═══════════════════ --}}
    <table class="meta" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <div class="m-label">Issued</div>
                <div class="m-value">{{ \App\Support\NepaliDate::display($invoice->created_at) }}</div>
            </td>
            <td>
                <div class="m-label">Due date</div>
                <div class="m-value {{ $isOverdue ? 'overdue' : '' }}">
                    {{ \App\Support\NepaliDate::display($invoice->due_date) }}
                </div>
            </td>
            <td>
                <div class="m-label">{{ $isPaid ? 'Paid on' : 'Balance due' }}</div>
                @if ($isPaid && $invoice->paid_date)
                    <div class="m-value paid">{{ \App\Support\NepaliDate::display($invoice->paid_date) }}</div>
                @else
                    <div class="m-value {{ $isOverdue ? 'overdue' : '' }}">Rs. {{ number_format($remaining, 2) }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ═══════════════════ PARTIES ═══════════════════ --}}
    <table class="parties" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <div class="p-label">Billed to</div>
                <div class="p-name">{{ $client->name }}</div>
                @if ($client->contact)
                    <div class="p-line">{{ $client->contact }}</div>
                @endif
                @if ($client->email)
                    <div class="p-line">{{ $client->email }}</div>
                @endif
                @if ($client->phone)
                    <div class="p-line">{{ $client->phone }}</div>
                @endif
            </td>
            <td>
                <div class="p-label">From</div>
                <div class="p-name">{{ $agency->agency_name ?? 'Madhyam' }}</div>
                @if ($agency->agency_address ?? null)
                    <div class="p-line">{{ $agency->agency_address }}</div>
                @endif
                @if ($agency->agency_email)
                    <div class="p-line">{{ $agency->agency_email }}</div>
                @endif
                @if ($agency->agency_phone)
                    <div class="p-line">{{ $agency->agency_phone }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ═══════════════════ DESCRIPTION ═══════════════════ --}}
    @if ($invoice->description)
        <div class="description-block">{{ $invoice->description }}</div>
    @endif

    {{-- ═══════════════════ LINE ITEMS ═══════════════════ --}}
    <table class="items" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <td style="width: 70%">Description</td>
                <td class="amount" style="width: 30%">Amount</td>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="width: 70%">
                    <div class="item-title">Services rendered</div>
                    @if ($invoice->description)
                        <div class="item-sub">{{ \Illuminate\Support\Str::limit($invoice->description, 90) }}</div>
                    @else
                        <div class="item-sub">Invoice #{{ $invoiceNumber }}</div>
                    @endif
                </td>
                <td class="amount" style="width: 30%">Rs. {{ number_format($invoice->amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- ═══════════════════ TOTALS ═══════════════════ --}}
    <table class="totals-wrap" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 50%"></td>
            <td style="width: 50%">
                <table class="totals" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="t-label">Subtotal</td>
                        <td class="t-value">Rs. {{ number_format($invoice->amount, 2) }}</td>
                    </tr>
                    @if ($invoice->discount_amount > 0)
                        <tr>
                            <td class="t-label">Discount</td>
                            <td class="t-value discount">− Rs. {{ number_format($invoice->discount_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr class="divider">
                        <td class="t-label">Invoice total</td>
                        <td class="t-value">Rs. {{ number_format($netAmount, 2) }}</td>
                    </tr>
                    @if ($totalPaid > 0)
                        <tr>
                            <td class="t-label">Amount paid</td>
                            <td class="t-value paid">− Rs. {{ number_format($totalPaid, 2) }}</td>
                        </tr>
                    @endif
                    <tr class="grand">
                        <td class="t-label">{{ $isPaid ? 'Paid' : 'Balance due' }}</td>
                        <td class="t-value">Rs. {{ number_format($isPaid ? $totalPaid : $remaining, 2) }}</td>
                    </tr>
                </table>
                <div class="status-line">
                    <span class="status-pill {{ $invoice->status }}">
                        @if ($isPaid)
                            Paid
                        @elseif ($isOverdue)
                            Overdue
                        @else
                            Pending
                        @endif
                    </span>
                </div>
            </td>
        </tr>
    </table>

    {{-- ═══════════════════ INSTALLMENT PROGRESS ═══════════════════ --}}
    @if ($isInstallment && $installmentPlan)
        @php
        $total = (int) ($installmentPlan['totalInstallments'] ?? 0);
        $paid = (int) ($installmentPlan['paidInstallments'] ?? 0);
        $per = (float) ($installmentPlan['amountPerInstallment'] ?? 0);
        $progressPct = $total > 0 ? round(($paid / $total) * 100) : 0;
    @endphp
        <div class="section">
            <div class="section-label">Payment plan</div>
            <div class="install-progress">
                <div class="install-caption">
                    <strong>{{ $paid }} of {{ $total }}</strong> installments paid &nbsp;·&nbsp; Rs. {{ number_format($per, 2) }} per
                    installment
                </div>
                <div class="install-bar">
                    <div class="install-bar-fill" style="width: {{ $progressPct }}%;"></div>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════ PAYMENT HISTORY ═══════════════════ --}}
    @if (count($payments) > 0)
        <div class="section">
            <div class="section-label">Payment history</div>
            <table class="payments" cellpadding="0" cellspacing="0">
                @foreach ($payments as $payment)
                    <tr>
                        <td class="pay-date" style="width: 20%">
                            {{ \App\Support\NepaliDate::display($payment->date) }}
                        </td>
                        <td class="pay-method" style="width: 20%">
                            {{ ucfirst($payment->method) }}
                            @if ($payment->verified)
                                <span class="verified-tick">✓</span>
                            @endif
                        </td>
                        <td class="pay-note" style="width: 35%">{{ $payment->note ?? '' }}</td>
                        <td class="pay-amt" style="width: 25%">Rs. {{ number_format($payment->amount, 2) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    {{-- ═══════════════════ FOOTER ═══════════════════ --}}
    <div class="footer">
        @if ($isPaid)
            <strong>Thank you for your payment.</strong>
            This document serves as your receipt.
        @else
            <strong>Thank you for your business.</strong>
            Please settle by {{ \App\Support\NepaliDate::display($invoice->due_date) }} to avoid service disruption.
        @endif
        <br />
        System-generated invoice · Generated {{ \App\Support\NepaliDate::display(now()) }}
    </div>
</body>
</html>
