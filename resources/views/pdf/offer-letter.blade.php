<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Offer of Employment — {{ $applicant->name }}</title>
    <style>
        @page  { margin: 30mm 25mm; }
        body   { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #1d1f25; line-height: 1.55; }
        .head  { border-bottom: 2px solid #0051d5; padding-bottom: 14px; margin-bottom: 28px; }
        .head h1 { font-size: 18pt; color: #0051d5; margin: 0 0 4px; letter-spacing: -0.01em; }
        .head .meta { font-size: 9pt; color: #6b7280; }
        .signature { margin-top: 42px; }
        .signature .row { display: table; width: 100%; }
        .signature .col { display: table-cell; width: 50%; vertical-align: top; }
        .signature .label { font-size: 9pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 4px; }
        .signature .line  { border-top: 1px solid #6b7280; margin-top: 32px; padding-top: 6px; font-size: 10pt; }
        table.terms { width: 100%; border-collapse: collapse; margin: 22px 0; }
        table.terms td { padding: 8px 12px; font-size: 10pt; border-bottom: 1px solid #e5e7eb; }
        table.terms td.k { color: #6b7280; width: 38%; text-transform: uppercase; letter-spacing: 0.06em; font-size: 9pt; }
        p { margin: 0 0 12px; }
    </style>
</head>
<body>
    <div class="head">
        <h1>CIHRM Ghana — Offer of Employment</h1>
        <div class="meta">Issued {{ now()->format('d M Y') }} · Confidential</div>
    </div>

    <p>Dear {{ $applicant->name }},</p>

    <p>
        We are delighted to offer you the position of <strong>{{ $job?->title ?? 'TBC' }}</strong>
        at CIHRM Ghana. The terms of this offer are set out below.
    </p>

    <table class="terms">
        <tr><td class="k">Position</td><td>{{ $job?->title ?? '—' }}</td></tr>
        <tr><td class="k">Reporting to</td><td>{{ $job?->department?->name ?? 'HR' }}</td></tr>
        @if ($salary)
            <tr><td class="k">Annual gross</td><td>GHS {{ number_format((float) $salary, 2) }}</td></tr>
        @endif
        @if ($startDate)
            <tr><td class="k">Start date</td><td>{{ \Carbon\Carbon::parse($startDate)->format('d M Y') }}</td></tr>
        @endif
        <tr><td class="k">Offer valid for</td><td>{{ $expiresIn }} days from the date of this letter</td></tr>
    </table>

    <p>
        This offer is subject to the standard checks listed in the candidate handbook and to your
        signed acceptance below. Once countersigned, your onboarding pack will be released.
    </p>

    <div class="signature">
        <div class="row">
            <div class="col">
                <div class="label">For CIHRM Ghana</div>
                <div class="line">{{ $sentBy ?? 'HR Team' }}</div>
            </div>
            <div class="col">
                <div class="label">Accepted by candidate</div>
                <div class="line">{{ $applicant->name }}</div>
            </div>
        </div>
    </div>
</body>
</html>
