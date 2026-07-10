<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $document->number }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            color: #1a1a1a;
        }
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .company-name {
            font-size: 20px;
            font-weight: bold;
        }
        .invoice-title {
            font-size: 24px;
            font-weight: bold;
            text-align: right;
        }
        .meta-table {
            width: 100%;
            margin-bottom: 24px;
            border-collapse: collapse;
        }
        .meta-table td {
            vertical-align: top;
            padding: 0;
        }
        .label {
            color: #666;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        table.lines {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        table.lines th,
        table.lines td {
            padding: 8px 6px;
            text-align: left;
        }
        table.lines th {
            font-size: 10px;
            text-transform: uppercase;
            color: #666;
            border-bottom: 1px solid #ddd;
        }
        table.lines td {
            border-bottom: 1px solid #f0f0f0;
            vertical-align: top;
        }
        table.lines th.text-right,
        table.lines td.text-right {
            text-align: right;
        }
        /* Explicit pixel widths on the header cells — dompdf handles this
           more predictably than table-layout:fixed + colgroup percentages,
           which can render inconsistently in its layout engine. */
        .col-item   { width: 200px; }
        .col-qty    { width: 50px; }
        .col-price  { width: 90px; }
        .col-disc   { width: 80px; }
        .col-tax    { width: 90px; }
        .col-total  { width: 90px; }

        .text-right {
            text-align: right;
        }
        .tax-name {
            display: block;
            font-size: 9px;
            color: #999;
        }
        .totals-table {
            width: 260px;
            margin-left: auto;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 4px 8px;
        }
        .totals-table .total-row td {
            font-weight: bold;
            font-size: 14px;
            border-top: 1px solid #1a1a1a;
            padding-top: 8px;
        }
        .footer {
            margin-top: 32px;
            font-size: 10px;
            color: #999;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <div class="company-name">Hassani Bachat Bazar</div>
            @if($document->location?->address)
                <div>{{ $document->location->address }}</div>
            @endif
        </div>
        <div>
            <div class="invoice-title">INVOICE</div>
            <div class="text-right">{{ $document->number }}</div>
        </div>
    </div>

    <table class="meta-table">
        <tr>
            <td style="width: 50%">
                <div class="label">Billed to</div>
                <div>{{ $document->contact?->name ?? 'Walk-in customer' }}</div>
                @if($document->contact?->phone)
                    <div>{{ $document->contact->phone }}</div>
                @endif
                @if($document->contact?->address)
                    <div>{{ $document->contact->address }}</div>
                @endif
            </td>
            <td style="width: 50%" class="text-right">
                <div class="label">Date</div>
                <div>{{ $document->confirmed_at?->format('M j, Y') ?? $document->created_at->format('M j, Y') }}</div>
                <div class="label" style="margin-top: 8px">Location</div>
                <div>{{ $document->location?->name }}</div>
                @if($document->user)
                    <div class="label" style="margin-top: 8px">Served by</div>
                    <div>{{ $document->user->name }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th class="col-item">Item</th>
                <th class="col-qty text-right">Qty</th>
                <th class="col-price text-right">Unit price</th>
                <th class="col-disc text-right">Discount</th>
                <th class="col-tax text-right">Tax</th>
                <th class="col-total text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($document->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="text-right">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }}</td>
                    <td class="text-right">Rs {{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="text-right">
                        @if($line->discount_amount > 0)
                            -Rs {{ number_format((float) $line->discount_amount, 2) }}
                            @if($line->discount_percent > 0)
                                <span class="tax-name">({{ rtrim(rtrim(number_format((float) $line->discount_percent, 2), '0'), '.') }}%)</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-right">
                        Rs {{ number_format((float) $line->tax_amount, 2) }}
                        @if($line->tax)
                            <span class="tax-name">{{ $line->tax->name }}</span>
                        @endif
                    </td>
                    <td class="text-right">Rs {{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">Rs {{ number_format((float) $document->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>Tax</td>
            <td class="text-right">Rs {{ number_format((float) $document->tax_total, 2) }}</td>
        </tr>
        @if($document->discount_type)
            @php
                $orderDiscountAmount = $document->discount_type === 'percentage'
                    ? (float) $document->subtotal * ((float) $document->discount_value / 100)
                    : (float) $document->discount_value;
            @endphp
            <tr>
                <td>Discount</td>
                <td class="text-right">
                    -Rs {{ number_format($orderDiscountAmount, 2) }}
                    @if($document->discount_type === 'percentage')
                        ({{ rtrim(rtrim(number_format((float) $document->discount_value, 2), '0'), '.') }}%)
                    @endif
                </td>
            </tr>
        @endif
        <tr class="total-row">
            <td>Total</td>
            <td class="text-right">Rs {{ number_format((float) $document->total, 2) }}</td>
        </tr>
    </table>

    @if($document->payments->isNotEmpty())
        <table class="totals-table" style="margin-top: 12px">
            @foreach($document->payments as $payment)
                <tr>
                    <td style="text-transform: capitalize">{{ str_replace('_', ' ', $payment->method) }}</td>
                    <td class="text-right">Rs {{ number_format((float) $payment->amount, 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="footer">
        Thank you for your business — Hassani Bachat Bazar
    </div>
</body>
</html>