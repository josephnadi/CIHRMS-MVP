<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bank Reconciliation — {{ $statement->statement_date->toDateString() }}</title>
    <style>
        body { font-family: 'Helvetica', Arial, sans-serif; color: #1a1a2e; margin: 0; padding: 24px; font-size: 11px; }
        .header { background: linear-gradient(135deg, #1a237e, #3949ab); color: white; padding: 14px 20px; border-radius: 10px; margin-bottom: 18px; }
        .header h1 { margin: 0; font-size: 16px; }
        .header p { margin: 4px 0 0; font-size: 10px; opacity: 0.85; }
        .meta { display: flex; gap: 24px; padding: 10px 0; border-bottom: 1px solid #e5e7eb; margin-bottom: 14px; }
        .meta div { font-size: 10px; }
        .meta .label { color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; }
        .meta .value { font-size: 12px; color: #1a1a2e; margin-top: 2px; }

        h2 { font-size: 12px; margin: 18px 0 6px; color: #1a237e; padding-bottom: 4px; border-bottom: 2px solid #1a237e; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th { background: #f5f5f9; color: #1a237e; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; padding: 6px 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; font-size: 10px; vertical-align: top; }
        td.num { text-align: right; font-family: 'Courier New', monospace; }
        td.mono { font-family: 'Courier New', monospace; }
        .dr { color: #be123c; }
        .cr { color: #047857; }

        .pill { display: inline-block; padding: 2px 6px; border-radius: 8px; font-size: 8px; font-weight: bold; text-transform: uppercase; }
        .pill-high { background: #d1fae5; color: #047857; }
        .pill-medium { background: #dbeafe; color: #1d4ed8; }
        .pill-low { background: #fef3c7; color: #b45309; }
        .pill-manual { background: #ede9fe; color: #6d28d9; }

        .summary-row { display: flex; gap: 18px; padding: 10px 14px; background: #f5f5f9; border-radius: 8px; margin-bottom: 14px; }
        .summary-row div { font-size: 10px; }
        .summary-row .value { font-size: 14px; font-weight: bold; color: #1a237e; }

        .footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #e5e7eb; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Bank Reconciliation Statement</h1>
        <p>{{ $bank->bank_name }} · {{ $bank->account_name }} · {{ $bank->currency }}</p>
    </div>

    <div class="meta">
        <div>
            <div class="label">Statement Date</div>
            <div class="value">{{ $statement->statement_date->toDateString() }}</div>
        </div>
        <div>
            <div class="label">Closing Balance</div>
            <div class="value">{{ $statement->currency ?? 'GHS' }} {{ number_format((float) $statement->closing_balance, 2) }}</div>
        </div>
        <div>
            <div class="label">Reconciled</div>
            <div class="value">{{ $reconciledCount }} / {{ $totalCount }} ({{ $totalCount > 0 ? round($reconciledCount / $totalCount * 100) : 0 }}%)</div>
        </div>
        <div>
            <div class="label">Imported</div>
            <div class="value">{{ $statement->created_at?->toDateTimeString() }}</div>
        </div>
    </div>

    <div class="summary-row">
        <div>
            <div class="label">Total Debits</div>
            <div class="value dr">{{ number_format($totalDr, 2) }}</div>
        </div>
        <div>
            <div class="label">Total Credits</div>
            <div class="value cr">{{ number_format($totalCr, 2) }}</div>
        </div>
        <div>
            <div class="label">Net Movement</div>
            <div class="value">{{ number_format($totalCr - $totalDr, 2) }}</div>
        </div>
    </div>

    <h2>Reconciled Lines ({{ $reconciledCount }})</h2>
    @if($reconciledCount > 0)
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Reference</th>
                    <th>Matched To</th>
                    <th>Confidence</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($reconciledLines as $line)
                    <tr>
                        <td class="mono">{{ $line->transaction_date?->toDateString() }}</td>
                        <td>{{ $line->description }}</td>
                        <td class="mono">{{ $line->reference ?? '—' }}</td>
                        <td class="mono">{{ $line->matched_reference ?? '—' }}</td>
                        <td>
                            <span class="pill pill-{{ $line->confidence ?? 'manual' }}">{{ $line->confidence ?? 'manual' }}</span>
                        </td>
                        <td class="num {{ (float) $line->amount < 0 ? 'dr' : 'cr' }}">
                            {{ number_format(abs((float) $line->amount), 2) }} {{ (float) $line->amount < 0 ? 'Dr' : 'Cr' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="font-size: 10px; color: #94a3b8;">No reconciled lines yet.</p>
    @endif

    <h2>Unmatched Lines ({{ $unmatchedCount }})</h2>
    @if($unmatchedCount > 0)
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Reference</th>
                    <th>Suggested</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($unmatchedLines as $line)
                    <tr>
                        <td class="mono">{{ $line->transaction_date?->toDateString() }}</td>
                        <td>{{ $line->description }}</td>
                        <td class="mono">{{ $line->reference ?? '—' }}</td>
                        <td>
                            @if($line->confidence)
                                <span class="pill pill-{{ $line->confidence }}">{{ $line->confidence }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="num {{ (float) $line->amount < 0 ? 'dr' : 'cr' }}">
                            {{ number_format(abs((float) $line->amount), 2) }} {{ (float) $line->amount < 0 ? 'Dr' : 'Cr' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="font-size: 10px; color: #94a3b8;">All lines reconciled.</p>
    @endif

    <div class="footer">
        Generated {{ now()->toDateTimeString() }} by {{ $generatedBy }} · CIHRM Ghana CIHRMS
    </div>
</body>
</html>
