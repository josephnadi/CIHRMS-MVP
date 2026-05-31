<?php

declare(strict_types=1);

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Models\Department;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\GhIpssBatchFileBuilder;
use App\Services\Disbursement\Providers\GhIpssAchProvider;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // .env.example ships GHIPSS_ENABLED=false (production-safe opt-in
    // default) so DisbursementServiceProvider doesn't register the GhIPSS
    // provider on its own. Tests in this file exercise dispatch through
    // BatchDisbursementService, which silently does nothing without a
    // provider — they need the channel enabled explicitly. Setting config
    // BEFORE the first app() resolution ensures the singleton picks up
    // the enabled state.
    config()->set('disbursement.providers.ghipss_ach.enabled', true);
    config()->set('disbursement.providers.ghipss_ach.sponsor_sort_code', '300100');
    config()->set('disbursement.providers.ghipss_ach.originator_name', 'CIHRM');

    $this->dept = Department::factory()->create();

    $this->employee = Employee::factory()->create([
        'department_id'        => $this->dept->id,
        'disbursement_channel' => DisbursementChannel::GhipssAch->value,
        'bank_account'         => '1234567890',
        'bank_sort_code'       => '300101',
    ]);

    $this->run = PayrollRun::create([
        'reference'    => 'PR-2026-05-GHIPSS',
        'period_year'  => 2026, 'period_month' => 5,
        'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'status'       => 'calculated',
    ]);

    $this->line = PayrollLine::create([
        'payroll_run_id' => $this->run->id,
        'employee_id'    => $this->employee->id,
        'basic' => 5000, 'allowance_total' => 0, 'gross' => 5000,
        'ssnit_base' => 5000, 'ssnit_tier1_employee' => 275, 'ssnit_tier1_employer' => 650,
        'nhia_split' => 125, 'tier2_employer' => 250, 'tier3_employee' => 0,
        'paye' => 600, 'voluntary_deductions' => 0,
        'net' => 4125,
        'status' => 'calculated',
    ]);
});

it('stages a GhIPSS row as Sent with a deterministic batch reference', function () {
    $provider = new GhIpssAchProvider(sponsorSortCode: '300100', originatorName: 'CIHRM GHANA');

    $svc = app(BatchDisbursementService::class);
    $svc->materialise($this->run);

    $d = Disbursement::where('payroll_run_id', $this->run->id)->first();
    expect($d->channel)->toBe(DisbursementChannel::GhipssAch);

    $result = $provider->send($d);

    expect($result->status)->toBe(DisbursementStatus::Sent);
    expect($result->providerReference)->toBe("GHIPSS-{$this->run->id}-{$d->id}");
    expect($result->raw)->toMatchArray([
        'staged_for_batch'  => true,
        'sponsor_sort_code' => '300100',
        'originator_name'   => 'CIHRM GHANA',
    ]);
});

it('fails the send if the beneficiary bank account is missing', function () {
    $provider = new GhIpssAchProvider(sponsorSortCode: '300100', originatorName: 'CIHRM');

    $d = Disbursement::create([
        'payroll_run_id'      => $this->run->id,
        'payroll_line_id'     => $this->line->id,
        'employee_id'         => $this->employee->id,
        'channel'             => DisbursementChannel::GhipssAch->value,
        'status'              => DisbursementStatus::Pending->value,
        'gross_amount'        => 4125,
        'e_levy'              => 0,
        'provider_fee'        => 0,
        'net_to_recipient'    => 4125,
        'beneficiary_account' => null,
        'beneficiary_name'    => 'Test',
    ]);

    $result = $provider->send($d);

    expect($result->status)->toBe(DisbursementStatus::Failed);
    expect($result->failureReason)->toContain('beneficiary bank account');
});

it('refreshStatus returns the persisted status unchanged (no remote poll)', function () {
    $provider = new GhIpssAchProvider(sponsorSortCode: '300100', originatorName: 'CIHRM');

    $d = Disbursement::create([
        'payroll_run_id'      => $this->run->id,
        'payroll_line_id'     => $this->line->id,
        'employee_id'         => $this->employee->id,
        'channel'             => DisbursementChannel::GhipssAch->value,
        'status'              => DisbursementStatus::Sent->value,
        'gross_amount'        => 4125, 'e_levy' => 0, 'provider_fee' => 0, 'net_to_recipient' => 4125,
        'beneficiary_account' => '1234567890', 'beneficiary_name' => 'Test',
        'provider_reference'  => 'GHIPSS-X-Y',
    ]);

    $result = $provider->refreshStatus($d);
    expect($result->status)->toBe(DisbursementStatus::Sent);
    expect($result->providerReference)->toBe('GHIPSS-X-Y');
});

it('reports its channel as ghipss_ach', function () {
    $provider = new GhIpssAchProvider(sponsorSortCode: '0', originatorName: 'X');
    expect($provider->channel())->toBe('ghipss_ach');
});

it('BatchDisbursementService dispatches GhIPSS rows via the registered provider', function () {
    config()->set('disbursement.providers.ghipss_ach.enabled', true);
    config()->set('disbursement.providers.ghipss_ach.sponsor_sort_code', '300100');
    config()->set('disbursement.providers.ghipss_ach.originator_name', 'CIHRM');

    $svc = app(BatchDisbursementService::class);
    $svc->materialise($this->run);

    $report = $svc->dispatch($this->run);

    expect($report['sent'])->toBe(1);
    expect($report['failed'])->toBe(0);
    expect($report['skipped'])->toBe(0);

    $d = Disbursement::where('payroll_run_id', $this->run->id)->first();
    expect($d->status)->toBe(DisbursementStatus::Sent);
    expect($d->provider_reference)->toStartWith('GHIPSS-');
    expect($d->sent_at)->not->toBeNull();
});

it('builds a CSV with header + one row per sent GhIPSS disbursement', function () {
    Storage::fake('local');

    $svc = app(BatchDisbursementService::class);
    $svc->materialise($this->run);
    $svc->dispatch($this->run);

    $builder = app(GhIpssBatchFileBuilder::class);
    $path = $builder->build($this->run);

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $contents = Storage::disk('local')->get($path);
    $lines = preg_split('/\r\n|\n/', trim($contents));

    expect($lines)->toHaveCount(2); // header + one data row
    expect($lines[0])->toBe('sequence_no,beneficiary_account,beneficiary_bank_sort_code,beneficiary_name,amount_ghs,narration,reference,originator_name,originator_sort_code,value_date');

    $data = str_getcsv($lines[1]);
    expect($data[0])->toBe('1');                       // sequence_no
    expect($data[1])->toBe('1234567890');               // beneficiary_account
    expect($data[2])->toBe('300101');                   // bank_sort_code
    expect($data[4])->toBe('4125.00');                  // amount in decimal GHS
    expect($data[6])->toStartWith('GHIPSS-');           // reference
});

it('excludes pending and failed rows from the batch file', function () {
    Storage::fake('local');

    $svc = app(BatchDisbursementService::class);
    $svc->materialise($this->run);

    // Manually flip the only row to Failed — file should be header-only
    Disbursement::query()->update(['status' => DisbursementStatus::Failed->value, 'failure_reason' => 'test']);

    $builder = app(GhIpssBatchFileBuilder::class);
    $path = $builder->build($this->run);

    $contents = Storage::disk('local')->get($path);
    $lines = preg_split('/\r\n|\n/', trim($contents));

    expect($lines)->toHaveCount(1); // header only
});

it('sanitises beneficiary name to keep commas out of the CSV', function () {
    Storage::fake('local');
    $this->employee->user->update(['name' => 'Asante, Kofi "Junior"']);

    $svc = app(BatchDisbursementService::class);
    $svc->materialise($this->run);
    $svc->dispatch($this->run);

    $builder = app(GhIpssBatchFileBuilder::class);
    $contents = $builder->preview($this->run);
    $lines = preg_split('/\r\n|\n/', trim($contents));
    $data  = str_getcsv($lines[1]);

    expect($data[3])->not->toContain(',');
    expect($data[3])->not->toContain('"');
    expect($data[3])->toContain('Asante');
    expect($data[3])->toContain('Kofi');
});

it('the disbursement:ghipss-export --print command outputs CSV to STDOUT', function () {
    $svc = app(BatchDisbursementService::class);
    $svc->materialise($this->run);
    $svc->dispatch($this->run);

    $exit = \Illuminate\Support\Facades\Artisan::call('disbursement:ghipss-export', [
        'run'     => $this->run->id,
        '--print' => true,
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('sequence_no,beneficiary_account');
    expect($output)->toContain('GHIPSS-');
});

it('the disbursement:ghipss-export command writes a file when --print is absent', function () {
    Storage::fake('local');
    $svc = app(BatchDisbursementService::class);
    $svc->materialise($this->run);
    $svc->dispatch($this->run);

    $this->artisan('disbursement:ghipss-export', ['run' => $this->run->id])
        ->expectsOutputToContain('GhIPSS batch file written')
        ->assertExitCode(0);

    expect(Storage::disk('local')->files('ghipss-batches'))->not->toBeEmpty();
});

it('the command exits FAILURE when the run id does not exist', function () {
    $this->artisan('disbursement:ghipss-export', ['run' => 99999])
        ->expectsOutputToContain('not found')
        ->assertExitCode(1);
});
