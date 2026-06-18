<!doctype html>
<html>
<head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
h1 { font-size: 16px; margin: 0 0 2px; } .sub { color: #666; margin: 0 0 12px; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
th { text-align: left; background: #f3f3f3; }
.r { text-align: right; } tfoot td { font-weight: bold; border-top: 2px solid #333; }
</style></head>
<body>
    <h1>Trial Balance</h1>
    <p class="sub">As of {{ $report['as_of'] }}</p>
    <table>
        <thead><tr><th>Code</th><th>Account</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead>
        <tbody>
        @foreach ($report['rows'] as $row)
            <tr>
                <td>{{ $row['code'] }}</td><td>{{ $row['name'] }}</td>
                <td class="r">{{ $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                <td class="r">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="2">Total</td>
            <td class="r">{{ number_format($report['total_debit'], 2) }}</td>
            <td class="r">{{ number_format($report['total_credit'], 2) }}</td>
        </tr></tfoot>
    </table>
</body>
</html>
