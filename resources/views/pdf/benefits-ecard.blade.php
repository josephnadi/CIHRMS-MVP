<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Benefits E-Card</title>
    <style>
        body { font-family: 'Helvetica', Arial, sans-serif; color: #1a1a2e; margin: 0; padding: 24px; }
        .card { border: 2px solid #0051d5; border-radius: 16px; padding: 24px; max-width: 600px; }
        .header { background: linear-gradient(135deg, #0051d5, #316bf3); color: white; padding: 16px 24px; border-radius: 12px; margin-bottom: 16px; }
        .header h1 { margin: 0; font-size: 18px; }
        .header p { margin: 4px 0 0; font-size: 11px; opacity: 0.85; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #e5e7eb; }
        .row:last-child { border-bottom: none; }
        .label { font-weight: bold; color: #64748b; font-size: 10px; text-transform: uppercase; }
        .value { font-size: 12px; }
        .footer { margin-top: 16px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #94a3b8; }
        h2 { font-size: 13px; margin-top: 18px; margin-bottom: 6px; color: #1a1a2e; }
    </style>
</head>
<body>
<div class="card">
    <div class="header">
        <h1>CIHRM Ghana — Benefits E-Card</h1>
        <p>{{ $plan->name }} ({{ strtoupper($plan->code) }})</p>
    </div>

    <div class="row"><span class="label">Employee</span><span class="value">{{ $employee->user?->name ?? 'N/A' }}</span></div>
    <div class="row"><span class="label">Employee #</span><span class="value">{{ $employee->employee_no }}</span></div>
    <div class="row"><span class="label">Plan</span><span class="value">{{ $plan->name }}</span></div>
    <div class="row"><span class="label">Type</span><span class="value">{{ ucfirst(str_replace('_',' ',$plan->type->value)) }}</span></div>
    <div class="row"><span class="label">Provider</span><span class="value">{{ $plan->provider ?? '—' }}</span></div>
    <div class="row"><span class="label">Effective</span><span class="value">{{ $enrolment->effective_from?->toDateString() }} → {{ $enrolment->effective_to?->toDateString() ?? 'ongoing' }}</span></div>
    <div class="row"><span class="label">Monthly Premium</span><span class="value">GHS {{ number_format((float) $enrolment->monthly_premium, 2) }}</span></div>

    @if($dependants->count() > 0)
    <h2>Covered Dependants</h2>
    @foreach($dependants->where('is_covered', true) as $dep)
    <div class="row"><span class="label">{{ ucfirst($dep->relationship->value) }}</span><span class="value">{{ $dep->full_name }} (DOB {{ $dep->date_of_birth?->toDateString() }})</span></div>
    @endforeach
    @endif

    <div class="footer">
        Issued: {{ now()->format('Y-m-d H:i') }} · This card is system-generated and valid while enrolment is active. Present alongside national ID at point of service.
    </div>
</div>
</body>
</html>
