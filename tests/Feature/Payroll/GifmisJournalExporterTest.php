<?php

declare(strict_types=1);

use App\Events\PayrollRunPaid;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\Gifmis\GifmisJournalExporter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * The most load-bearing assertion in this whole file is the double-entry
 * balance check: sum of debits === sum of credits. If that ever breaks,
 * we'd ship an unbalanced JV to GIFMIS and the state accountant would
 * reject the upload, blocking the entire MDA's payroll posting until
 * we shipped a hotfix. The exporter throws on imbalance — these tests
 * lock that in.
 */

beforeEach(function () {
    $this->dept = Department::factory()->create(['code' => 'HR']);
    $this->user = User::factory()->create(['name' => 'Akosua Mensah']);
    $this->employee = Employee::factory()->create([
        'department_id' => $this->dept->id,
        'user_id'       => $this->user->id,
    ]);

    $this->run = PayrollRun::create([
        'reference'    => 'PR-2026-05-HR',
        'period_year'  => 2026,
        'period_month' => 5,
        'period_start' => '2026-05-01',
        'period_end'   => '2026-05-31',
        'status'       => 'calculated',
    ]);

    // Fixture is *internally consistent* for double-entry accounting:
    //   net = gross − paye − ssnit_employee − tier3_employee − voluntary
    //       = 5250 − 645.50 − 288.75 − 50.00 − 100.00
    //       = 4165.75
    // If the JV doesn't balance with this fixture, the exporter has a bug
    // (not the fixture).
    PayrollLine::create([
        'payroll_run_id'       => $this->run->id,
        'employee_id'          => $this->employee->id,
        'basic'                => 5000.00,
        'allowance_total'      => 250.00,
        'gross'                => 5250.00,
        'ssnit_base'           => 5250.00,
        'ssnit_tier1_employee' => 288.75,
        'ssnit_tier1_employer' => 682.50,
        'nhia_split'           => 131.25,
        'tier2_employer'       => 262.50,
        'tier3_employee'       => 50.00,
        'paye'                 => 645.50,
        'voluntary_deductions' => 100.00,
        'net'                  => 4165.75,
        'status'               => 'calculated',
    ]);

    $this->glCodes = [
        'dr_salary'          => 'D-SAL',
        'dr_ssnit_employer'  => 'D-SSE',
        'dr_tier2_employer'  => 'D-TR2',
        'cr_net_payable'     => 'C-NET',
        'cr_paye'            => 'C-PAY',
        'cr_ssnit_employee'  => 'C-SSI',
        'cr_ssnit_employer'  => 'C-SSE',
        'cr_nhia'            => 'C-NHI',
        'cr_tier2'           => 'C-TR2',
        'cr_tier3'           => 'C-TR3',
        'cr_voluntary'       => 'C-VOL',
    ];
});

it('emits a balanced journal where total debits equal total credits', function () {
    $exporter = new GifmisJournalExporter(costCentre: '0001-23-45', glCodes: $this->glCodes);
    $journal  = $exporter->journal($this->run);

    expect($journal['totals']['debit'])->toBe($journal['totals']['credit']);
    expect($journal['totals']['debit'])->toBeGreaterThan(0.0);
});

it('throws when the journal would not balance (calculator residual)', function () {
    $exporter = new GifmisJournalExporter(costCentre: '0001-23-45', glCodes: $this->glCodes);

    // Make the row genuinely unbalanced by mutating gross + leaving net alone:
    // gross goes up, but every other line stays the same → debits no longer
    // equal credits.
    PayrollLine::query()->update(['gross' => 9999.99]);

    expect(fn () => $exporter->journal($this->run))
        ->toThrow(\RuntimeException::class, 'GIFMIS journal not balanced');
});

it('splits employer SSNIT credit so the NHIA portion posts to its own GL', function () {
    $exporter = new GifmisJournalExporter(costCentre: '0001-23-45', glCodes: $this->glCodes);
    $journal  = $exporter->journal($this->run);

    $byGl = collect($journal['lines'])->keyBy('gl_code');

    // SSNIT-employer credit = 682.50 (debit total) - 131.25 (NHIA split) = 551.25
    expect((float) $byGl['C-SSE']['cr_amount'])->toBe(551.25);
    expect((float) $byGl['C-NHI']['cr_amount'])->toBe(131.25);
    // Together they sum back to the employer debit total.
    expect((float) $byGl['C-SSE']['cr_amount'] + (float) $byGl['C-NHI']['cr_amount'])
        ->toBe((float) $byGl['D-SSE']['dr_amount']);
});

it('uses the configured cost centre on every detail line', function () {
    $exporter = new GifmisJournalExporter(costCentre: '0042-99-01', glCodes: $this->glCodes);
    $journal  = $exporter->journal($this->run);

    foreach ($journal['lines'] as $line) {
        expect($line['cost_centre'])->toBe('0042-99-01');
    }
});

it('emits no rows for zero-amount buckets', function () {
    // Bump net up by the 150 we're removing (50 tier3 + 100 voluntary) so the
    // JV still balances after we zero those deductions.
    PayrollLine::query()->update([
        'tier3_employee'       => 0,
        'voluntary_deductions' => 0,
        'net'                  => 4315.75, // 5250 − 645.50 − 288.75
    ]);

    $exporter = new GifmisJournalExporter(costCentre: '0001-23-45', glCodes: $this->glCodes);
    $journal  = $exporter->journal($this->run);

    $glCodes = collect($journal['lines'])->pluck('gl_code')->all();
    expect($glCodes)->not->toContain('C-TR3');     // no tier3 row
    expect($glCodes)->not->toContain('C-VOL');     // no voluntary row
});

it('aggregates correctly across multiple PayrollLines', function () {
    PayrollLine::create([
        'payroll_run_id'       => $this->run->id,
        'employee_id'          => Employee::factory()->create()->id,
        'basic'                => 3000, 'allowance_total' => 0, 'gross' => 3000,
        'ssnit_base'           => 3000, 'ssnit_tier1_employee' => 165, 'ssnit_tier1_employer' => 390,
        'nhia_split'           => 75, 'tier2_employer' => 150, 'tier3_employee' => 0,
        'paye'                 => 234, 'voluntary_deductions' => 0,
        'net'                  => 2601, 'status' => 'calculated',
    ]);

    $exporter = new GifmisJournalExporter(costCentre: '0001-23-45', glCodes: $this->glCodes);
    $journal  = $exporter->journal($this->run);
    $byGl = collect($journal['lines'])->keyBy('gl_code');

    expect((float) $byGl['D-SAL']['dr_amount'])->toBe(5250.0 + 3000.0);  // gross sum
    expect((float) $byGl['C-NET']['cr_amount'])->toBe(4165.75 + 2601.0); // net sum (post-fixture balance)
    expect($journal['totals']['debit'])->toBe($journal['totals']['credit']);
});

it('the CSV layout has header + N lines + trailer with totals', function () {
    $exporter = new GifmisJournalExporter(costCentre: '0001-23-45', glCodes: $this->glCodes);
    $output   = $exporter->preview($this->run);
    $lines    = preg_split('/\r\n|\n/', trim($output));

    // Header row
    expect($lines[0])->toBe('journal_id|line_no|gl_code|cost_centre|dr_amount|cr_amount|narration|period|source_doc|reference');

    // Trailer row
    $trailer = end($lines);
    expect($trailer)->toStartWith('*TOTALS*|');
    expect($trailer)->toContain('Sum debits === Sum credits');

    // Trailer's debit and credit columns must match
    $cells = explode('|', $trailer);
    expect($cells[4])->toBe($cells[5]);
});

it('writes a file to the configured disk when build() is called', function () {
    Storage::fake('local');

    $exporter = new GifmisJournalExporter(costCentre: '0001-23-45', glCodes: $this->glCodes, disk: 'local');
    $path = $exporter->build($this->run);

    expect(Storage::disk('local')->exists($path))->toBeTrue();
    expect($path)->toStartWith('gifmis-journals/JV-');
    expect($path)->toEndWith('.csv');
});

it('payroll:gifmis-export --print streams the JV to STDOUT', function () {
    $exit = \Illuminate\Support\Facades\Artisan::call('payroll:gifmis-export', [
        'run' => $this->run->id, '--print' => true,
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('journal_id|line_no|gl_code');
    expect($output)->toContain('JV-PR-2026-05-HR');
    expect($output)->toContain('*TOTALS*');
});

it('payroll:gifmis-export writes a file when --print is absent', function () {
    Storage::fake('local');

    $exit = \Illuminate\Support\Facades\Artisan::call('payroll:gifmis-export', ['run' => $this->run->id]);

    expect($exit)->toBe(0);
    expect(Storage::disk('local')->files('gifmis-journals'))->not->toBeEmpty();
});

it('payroll:gifmis-export exits FAILURE on unknown run id', function () {
    $exit = \Illuminate\Support\Facades\Artisan::call('payroll:gifmis-export', ['run' => 999999]);
    expect($exit)->toBe(1);
});

it('the HTTP download endpoint streams a text/csv attachment to authorised users', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $response = $this->actingAs($finance)
        ->get(route('payroll-runs.gifmis-export', $this->run));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))
        ->toContain('GIFMIS-JV-PR-2026-05-HR.csv');
    expect($response->getContent())->toContain('journal_id|line_no|');
});

it('rejects the download for users without statutory.export permission', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->get(route('payroll-runs.gifmis-export', $this->run))
        ->assertForbidden();
});

it('markPaid fires PayrollRunPaid event', function () {
    Event::fake();

    $this->run->update(['status' => 'approved']);
    app(\App\Services\Payroll\PayrollService::class)->markPaid($this->run->fresh());

    Event::assertDispatched(PayrollRunPaid::class, fn ($e) => $e->run->id === $this->run->id);
});
