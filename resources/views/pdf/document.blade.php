<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $label }} {{ $number }}</title>
<style>
    @page { margin: 36px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
    h1 { font-size: 22px; margin: 0; }
    .muted { color: #666666; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .right { text-align: right; }
    .meta td { padding: 2px 0; }
    .lines th { text-align: left; border-bottom: 1px solid #1a1a1a; padding: 6px 4px; font-size: 9px; }
    .lines td { border-bottom: 1px solid #dddddd; padding: 6px 4px; vertical-align: top; }
    .lines th.right, .lines td.right { text-align: right; }
    .totals { width: 40%; margin-left: 60%; margin-top: 14px; }
    .totals td { padding: 3px 0; }
    .grand td { border-top: 1px solid #1a1a1a; font-weight: bold; font-size: 12px; padding-top: 6px; }
    .block { margin-top: 18px; }
    .block h2 { font-size: 11px; margin: 0 0 4px 0; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <h1>{{ $tenantName }}</h1>
        </td>
        <td class="right">
            <h1>{{ strtoupper($label) }}</h1>
            <div>{{ $number }}</div>
        </td>
    </tr>
</table>

<table style="margin-top: 18px;">
    <tr>
        <td style="width: 55%; vertical-align: top;">
            @if ($billTo)
                <div class="muted">Bill to</div>
                <div><strong>{{ $billTo }}</strong></div>
            @endif
            <div style="margin-top: 6px;"><strong>{{ $title }}</strong></div>
        </td>
        <td style="vertical-align: top;">
            <table class="meta">
                @foreach ($meta as $row)
                    <tr>
                        <td class="muted">{{ $row[0] }}</td>
                        <td class="right">{{ $row[1] }}</td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

<table class="lines" style="margin-top: 18px;">
    <thead>
        <tr>
            <th>Item</th>
            <th class="right">Qty</th>
            <th class="right">Unit price</th>
            <th class="right">Disc %</th>
            <th class="right">Tax</th>
            <th class="right">Line total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $l)
            <tr>
                <td>
                    <strong>{{ $l['name'] }}</strong>
                    @if ($l['sku'])
                        <div class="muted">{{ $l['sku'] }}</div>
                    @endif
                    @if ($l['description'])
                        <div class="muted">{{ $l['description'] }}</div>
                    @endif
                </td>
                <td class="right">{{ $l['quantity'] }}</td>
                <td class="right">{{ $l['unit_price'] }}</td>
                <td class="right">{{ $l['discount'] }}</td>
                <td class="right">{{ $l['tax'] }}</td>
                <td class="right">{{ $l['total'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="muted">Subtotal</td><td class="right">{{ $subtotal }}</td></tr>
    <tr><td class="muted">Tax</td><td class="right">{{ $taxTotal }}</td></tr>
    <tr class="grand"><td>Total</td><td class="right">{{ $total }}</td></tr>
</table>

@if ($notes)
    <div class="block">
        <h2>Notes</h2>
        <div class="muted">{!! nl2br(e($notes)) !!}</div>
    </div>
@endif

@if ($terms)
    <div class="block">
        <h2>Terms</h2>
        <div class="muted">{!! nl2br(e($terms)) !!}</div>
    </div>
@endif
</body>
</html>
