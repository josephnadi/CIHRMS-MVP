<!doctype html>
<html>
<head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
h1 { font-size: 16px; margin: 0 0 2px; } .sub { color: #666; margin: 0 0 12px; }
h2 { font-size: 12px; margin: 12px 0 4px; }
table { width: 100%; border-collapse: collapse; } th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
th { text-align: left; background: #f3f3f3; } .r { text-align: right; }
</style></head>
<body>
    <h1>Finance Analytics</h1>
    <p class="sub">FY {{ $year }} — {{ $from }} to {{ $to }}</p>

    <h2>Key indicators</h2>
    <table>
        <tbody>
        @foreach ($kpis as $label => $value)
            <tr><td>{{ ucwords(str_replace('_', ' ', $label)) }}</td><td class="r">{{ number_format((float) $value, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <h2>Monthly trend</h2>
    <table>
        <thead><tr><th>Month</th><th class="r">Income</th><th class="r">Expenditure</th><th class="r">Surplus</th><th class="r">Cash</th></tr></thead>
        <tbody>
        @foreach ($trends['months'] as $i => $m)
            <tr><td>{{ $m }}</td>
                <td class="r">{{ number_format($trends['income'][$i], 2) }}</td>
                <td class="r">{{ number_format($trends['expenditure'][$i], 2) }}</td>
                <td class="r">{{ number_format($trends['surplus'][$i], 2) }}</td>
                <td class="r">{{ number_format($trends['cash'][$i], 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
