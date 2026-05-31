<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\Ippd\IppdExporter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // .env.example ships IPPD_MDA_CODE='' (intentionally blank — operators
    // must supply their own MDA before going live). The DI-resolved IppdExporter
    // reads from config and the empty string is a value, so the binding's
    // 'CIHRMS' fallback never fires. Tests that round-trip through the
    // artisan command / HTTP route need the MDA set explicitly; tests that
    // construct IppdExporter directly already pass mdaCode in the
    // constructor and aren't affected.
    config()->set('payroll.ippd.mda_code', 'CIHRMS');

    $this->dept = Department::factory()->create(['code' => 'HR']);
    $this->user = User::factory()->create(['name' => 'Akosua Mensah']);
    $this->employee = Employee::factory()->create([
        'department_id'  => $this->dept->id,
        'user_id'        => $this->user->id,
        'employee_no'    => 'CIHRM-0042',
        'bank_account'   => '1234567890',
        'bank_sort_code' => '300101',
    ]);

    $this->run = PayrollRun::create([
        'reference'    => 'PR-2026-05-HR',
        'period_year'  => 2026,
        'period_month' => 5,
        'period_start' => '2026-05-01',
        'period_end'   => '2026-05-31',
        'status'       => 'calculated',
    ]);

    $this->line = PayrollLine::create([
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
        'tier3_employee'       => 0,
        'paye'                 => 645.50,
        'voluntary_deductions' => 100.00,
        'net'                  => 3915.75,
        'status'               => 'calculated',
    ]);
});

it('emits an H header line with the MDA code, period, and IPPD3 marker', function () {
    $exporter = new IppdExporter(mdaCode: 'MOH001', disk: 'local');
    $output   = $exporter->preview($this->run);
    $lines    = preg_split('/\r\n|\n/', trim($output));

    expect($lines[0])->toStartWith('H|MOH001|PR-2026-05-HR|202605|20260501|20260531|1|');
    expect($lines[0])->toEndWith('|IPPD3');
});

it('emits one D detail line per PayrollLine with pesewa amounts', function () {
    $exporter = new IppdExporter(mdaCode: 'CIHRMS', disk: 'local');
    $output   = $exporter->preview($this->run);
    $lines    = preg_split('/\r\n|\n/', trim($output));

    expect($lines)->toHaveCount(3); // H + 1 D + T

    $detail = $lines[1];
    $cells = explode('|', $detail);

    expect($cells[0])->toBe('D');
    expect(trim($cells[1]))->toBe('CIHRM-0042');
    expect(trim($cells[2]))->toBe('Akosua');         // surname column = first whitespace token
    expect(trim($cells[3]))->toBe('Mensah');         // other_names
    expect(trim($cells[4]))->toBe('HR');             // ministry code falls back to department code
    expect(trim($cells[7]))->toBe('300101');         // bank sort code
    expect(trim($cells[8]))->toBe('1234567890');     // bank account
    expect(trim($cells[9]))->toBe('500000');         // basic in pesewas
    expect(trim($cells[10]))->toBe('25000');         // allowance in pesewas
    expect(trim($cells[11]))->toBe('525000');        // gross in pesewas
    expect(trim($cells[12]))->toBe('64550');         // paye
    expect(trim($cells[13]))->toBe('28875');         // ssnit employee
    expect(trim($cells[14]))->toBe('68250');         // ssnit employer
    expect(trim($cells[15]))->toBe('13125');         // nhia
    expect(trim($cells[16]))->toBe('26250');         // tier2
    expect(trim($cells[19]))->toBe('391575');        // net
});

it('emits a T trailer line with totals that reconcile to the detail row sum', function () {
    $exporter = new IppdExporter(mdaCode: 'CIHRMS', disk: 'local');
    $lines = preg_split('/\r\n|\n/', trim($exporter->preview($this->run)));

    $trailer = explode('|', end($lines));
    expect($trailer[0])->toBe('T');
    expect($trailer[1])->toBe('1');                  // record count
    expect($trailer[2])->toBe('525000');             // gross total
    expect($trailer[3])->toBe('64550');              // paye total
    expect($trailer[10])->toBe('391575');            // net total
});

it('aggregates trailer totals correctly across multiple lines', function () {
    PayrollLine::create([
        'payroll_run_id'       => $this->run->id,
        'employee_id'          => Employee::factory()->create()->id,
        'basic'                => 3000.00,
        'allowance_total'      => 0,
        'gross'                => 3000.00,
        'ssnit_base'           => 3000.00,
        'ssnit_tier1_employee' => 165.00,
        'ssnit_tier1_employer' => 390.00,
        'nhia_split'           => 75.00,
        'tier2_employer'       => 150.00,
        'tier3_employee'       => 0,
        'paye'                 => 234.00,
        'voluntary_deductions' => 0,
        'net'                  => 2601.00,
        'status'               => 'calculated',
    ]);

    $exporter = new IppdExporter(mdaCode: 'CIHRMS', disk: 'local');
    $lines = preg_split('/\r\n|\n/', trim($exporter->preview($this->run)));
    $trailer = explode('|', end($lines));

    expect($trailer[1])->toBe('2');                          // 2 records
    expect($trailer[2])->toBe((string) (525000 + 300000));   // gross total in pesewas
    expect($trailer[10])->toBe((string) (391575 + 260100));  // net total
});

it('writes a file to the configured disk when build() is called', function () {
    Storage::fake('local');

    $exporter = new IppdExporter(mdaCode: 'CIHRMS', disk: 'local');
    $path = $exporter->build($this->run);

    expect(Storage::disk('local')->exists($path))->toBeTrue();
    expect($path)->toStartWith('ippd-exports/IPPD-');
    expect($path)->toEndWith('.csv');
});

it('strips pipes from name fields so they never collide with the delimiter', function () {
    $this->user->update(['name' => 'O|Reilly Kofi']);

    $exporter = new IppdExporter(mdaCode: 'CIHRMS', disk: 'local');
    $lines = preg_split('/\r\n|\n/', trim($exporter->preview($this->run)));

    $cells = explode('|', $lines[1]);
    // The pipe inside the name MUST become a space — otherwise the column
    // count would shift right and CAGD's parser would mis-route every
    // following value.
    expect(count($cells))->toBe(count((new \ReflectionClass(IppdExporter::class))->getConstants()['IPPD_COLUMNS'] ?? []) ?: 20);
    expect(trim($cells[2]))->not->toContain('|');
});

it('transliterates non-ASCII characters because CAGD parser is ASCII-only', function () {
    $this->user->update(['name' => 'Désirée Çompañé']);

    $exporter = new IppdExporter(mdaCode: 'CIHRMS', disk: 'local');
    $output   = $exporter->preview($this->run);

    expect($output)->not->toContain('é');
    expect($output)->not->toContain('Ç');
    expect($output)->not->toContain('ñ');
});

it('truncates over-width fields instead of overflowing into the next column', function () {
    $this->user->update(['name' => str_repeat('A', 80) . ' ' . str_repeat('B', 80)]);

    $exporter = new IppdExporter(mdaCode: 'CIHRMS', disk: 'local');
    $cells = explode('|', preg_split('/\r\n|\n/', trim($exporter->preview($this->run)))[1]);

    // Surname column width is 40 → 80 A's should truncate to 40.
    expect(strlen($cells[2]))->toBe(40);
    expect($cells[2])->toBe(str_repeat('A', 40));
});

it('falls back to the configured MDA code when an employee has no department code', function () {
    $this->employee->update(['department_id' => null]);

    // MDA code is constrained to 6 chars by the ministry_code column width,
    // so use a 6-char value to keep the assertion exact.
    $exporter = new IppdExporter(mdaCode: 'MOH001', disk: 'local');
    $cells = explode('|', preg_split('/\r\n|\n/', trim($exporter->preview($this->run)))[1]);

    expect(trim($cells[4]))->toBe('MOH001');
});

it('payroll:ippd-export --print streams the export to STDOUT', function () {
    $exit = \Illuminate\Support\Facades\Artisan::call('payroll:ippd-export', [
        'run' => $this->run->id, '--print' => true,
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('H|CIHRMS|PR-2026-05-HR|202605|');
    expect($output)->toContain('|CIHRM-0042');
    expect($output)->toContain('T|1|');
});

it('payroll:ippd-export writes a file when --print is absent', function () {
    Storage::fake('local');

    $exit = \Illuminate\Support\Facades\Artisan::call('payroll:ippd-export', ['run' => $this->run->id]);

    expect($exit)->toBe(0);
    expect(Storage::disk('local')->files('ippd-exports'))->not->toBeEmpty();
});

it('payroll:ippd-export exits FAILURE on unknown run id', function () {
    $exit = \Illuminate\Support\Facades\Artisan::call('payroll:ippd-export', ['run' => 999999]);
    expect($exit)->toBe(1);
});

it('the HTTP download endpoint streams a text/csv attachment to authorised users', function () {
    // statutory.export is granted to finance_officer + auditor (per
    // User::ROLE_PERMISSIONS) — match the gate in routes/web.php exactly.
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $response = $this->actingAs($finance)
        ->get(route('payroll-runs.ippd-export', $this->run));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toContain('IPPD-PR-2026-05-HR.csv');
    expect($response->getContent())->toContain('H|CIHRMS|PR-2026-05-HR|');
});

it('rejects the download for a user without statutory.export permission', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->get(route('payroll-runs.ippd-export', $this->run))
        ->assertForbidden();
});
