<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $data['title'] }}</title>
    <style>
        body { font: 13px/1.45 system-ui, "Segoe UI", Tahoma, "Noto Naskh Arabic", sans-serif; color: #1c1917; margin: 0; background: #f5f5f4; }
        .page { max-width: 1000px; margin: 16px auto; background: #fff; padding: 28px 32px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
        h1 { font-size: 22px; margin: 0 0 2px; }
        h2 { font-size: 15px; margin: 22px 0 6px; padding-bottom: 3px; border-bottom: 2px solid #e7e5e4; }
        .meta { color: #57534e; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th, td { text-align: start; padding: 4px 6px; border-bottom: 1px solid #e7e5e4; vertical-align: top; }
        th { background: #fafaf9; font-weight: 600; font-size: 12px; }
        .num { text-align: end; font-variant-numeric: tabular-nums; }
        .note { color: #78716c; font-size: 12px; margin: 2px 0 0; }
        .skipped { background: #fffbeb; border: 1px solid #fde68a; padding: 8px 12px; border-radius: 4px; margin: 10px 0; }
        .bar { position: sticky; top: 0; background: #1c1917; color: #fff; padding: 8px 16px; display: flex; gap: 12px; align-items: center; }
        .bar button { font: inherit; padding: 6px 14px; border: 0; border-radius: 4px; background: #f5b301; cursor: pointer; }
        .insights { white-space: pre-line; }
        @media print { body { background: #fff; } .bar { display: none; } .page { box-shadow: none; margin: 0; padding: 0; max-width: none; } h2 { break-after: avoid; } tr { break-inside: avoid; } @page { margin: 14mm; } }
    </style>
</head>
<body>
<div class="bar"><button onclick="window.print()">Print or save as PDF</button><span>In the print window, choose "Save as PDF" as the printer.</span></div>
<div class="page">
    <h1>{{ $data['title'] }}</h1>
    <div class="meta">Period {{ \Carbon\CarbonImmutable::parse($data['period']['from'])->format('d M Y') }} to {{ \Carbon\CarbonImmutable::parse($data['period']['to'])->format('d M Y') }} · created {{ $data['generated'] }}</div>
    @if ($data['skipped'])
        <div class="skipped"><strong>Not included</strong> (you do not have permission to see it): {{ implode('; ', $data['skipped']) }}.</div>
    @endif
    @foreach ($data['sections'] as $section)
        <h2>{{ $section['title'] }}</h2>
        @if ($section['rows'] === [])
            <p class="note">Nothing in this period.</p>
        @else
            <table>
                <thead><tr>@foreach ($section['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($section['rows'] as $row)
                    <tr>
                        @foreach (array_values($row) as $cell)
                            @php($bold = is_array($cell) && array_key_exists('b', $cell))
                            @php($value = $bold ? $cell['b'] : $cell)
                            <td class="{{ is_int($value) || is_float($value) ? 'num' : '' }} {{ $section['key'] === 'insights' ? 'insights' : '' }}">@if ($bold)<strong>{{ $value }}</strong>@elseif (is_float($value)){{ number_format($value, 2) }}@else{{ $value }}@endif</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
        @if (! empty($section['note']))<p class="note">{{ $section['note'] }}</p>@endif
    @endforeach
</div>
</body>
</html>
