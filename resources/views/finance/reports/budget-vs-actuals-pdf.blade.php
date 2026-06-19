<!doctype html>
<html>
<head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
h1 { font-size: 16px; margin: 0 0 2px; } .sub { color: #666; margin: 0 0 12px; }
h2 { font-size: 12px; margin: 12px 0 4px; text-transform: uppercase; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
th { text-align: left; background: #f3f3f3; }
.r { text-align: right; } tfoot td { font-weight: bold; border-top: 2px solid #333; }
.bad { color: #b00020; font-weight: bold; }
</style></head>
<body>
    <h1>Budget vs Actuals</h1>
    <p class="sub">Fiscal year {{ $report['year'] }} — through period {{ $report['as_of_period'] }} ({{ $report['as_of'] }})</p>

    @foreach ($report['groups'] as $group)
        <h2>{{ ucfirst($group['type']) }}</h2>
        <table>
            <thead><tr><th>Account</th><th class="r">Annual</th><th class="r">YTD budget</th><th class="r">YTD actual</th><th class="r">Variance</th></tr></thead>
            <tbody>
            @foreach ($group['rows'] as $row)
                <tr>
                    <td>{{ $row['code'] }} {{ $row['name'] }}</td>
                    <td class="r">{{ number_format($row['annual_budget'], 2) }}</td>
                    <td class="r">{{ number_format($row['ytd_budget'], 2) }}</td>
                    <td class="r">{{ number_format($row['ytd_actual'], 2) }}</td>
                    <td class="r {{ $row['favourable'] === false ? 'bad' : '' }}">{{ number_format($row['variance'], 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr>
                <td>Total {{ ucfirst($group['type']) }}</td>
                <td class="r">{{ number_format($group['annual_budget'], 2) }}</td>
                <td class="r">{{ number_format($group['ytd_budget'], 2) }}</td>
                <td class="r">{{ number_format($group['ytd_actual'], 2) }}</td>
                <td class="r">{{ number_format($group['variance'], 2) }}</td>
            </tr></tfoot>
        </table>
    @endforeach

    <p style="margin-top:12px;font-weight:bold;">Grand total variance: {{ number_format($report['totals']['variance'], 2) }}</p>
</body>
</html>
