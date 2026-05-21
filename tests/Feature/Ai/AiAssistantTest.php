<?php

use App\Enums\EmployeeStatus;
use App\Http\Controllers\AiAssistantController;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\EmployeeSummaryService;
use App\Services\Ai\PiiRedactor;
use App\Services\Ai\Providers\FakeLlmProvider;

beforeEach(function () {
    $this->fake = new FakeLlmProvider();
    $this->app->instance(LlmProvider::class, $this->fake);

    // Rebuild the orchestrator so it picks up our fake instead of the
    // singleton resolved at boot time.
    $this->app->forgetInstance(EmployeeSummaryService::class);
    $this->app->forgetInstance(AiAssistantController::class);

    $this->hr   = User::factory()->create(['role' => 'hr_admin']);
    $this->dept = Department::factory()->create(['name' => 'Finance']);
});

it('returns the LLM-generated summary for an employee', function () {
    $employee = Employee::factory()->create([
        'department_id' => $this->dept->id,
        'employee_no'   => 'CIHRM-AI-1',
        'position'      => 'Senior Accountant',
        'status'        => EmployeeStatus::Active->value,
    ]);

    $this->fake->queue('Test reply: senior accountant in Finance, currently active.');

    $this->actingAs($this->hr)
        ->postJson(route('ai.employee-summary'), ['employee_id' => $employee->id])
        ->assertOk()
        ->assertJson([
            'summary' => 'Test reply: senior accountant in Finance, currently active.',
            'model'   => 'fake-haiku-1',
        ])
        ->assertJsonStructure(['summary', 'model', 'usage' => [
            'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_creation_tokens',
        ]]);
});

it('redacts PII before sending anything to the LLM', function () {
    $employee = Employee::factory()->create([
        'department_id'           => $this->dept->id,
        'employee_no'             => 'CIHRM-AI-2',
        'position'                => 'Director',
        'phone'                   => '+233244999888',
        'national_id'             => 'GHA-987654321-X',
        'ssnit_number'            => 'C123456789012',
        'tin_number'              => 'P0001234567',
        'bank_account'            => '0123456789012',
        'salary'                  => 25_000,
        'address'                 => '12 Liberation Rd, Accra',
        'emergency_contact_phone' => '+233200111222',
    ]);

    $this->actingAs($this->hr)
        ->postJson(route('ai.employee-summary'), ['employee_id' => $employee->id])
        ->assertOk();

    expect($this->fake->calls)->toHaveCount(1);

    $call = $this->fake->calls[0];
    $haystack = $call['system'].'|'.$call['cached'].'|'.$call['user'];

    expect($haystack)
        ->not->toContain('+233244999888')
        ->not->toContain('GHA-987654321-X')
        ->not->toContain('C123456789012')
        ->not->toContain('P0001234567')
        ->not->toContain('0123456789012')
        ->not->toContain('25000')
        ->not->toContain('25,000')
        ->not->toContain('Liberation Rd')
        ->not->toContain('+233200111222');

    // But the safe fields should be present
    expect($call['user'])
        ->toContain('CIHRM-AI-2')
        ->toContain('Director')
        ->toContain('Finance');
});

it('keeps the system + cached prefix byte-identical across calls (cache friendly)', function () {
    $a = Employee::factory()->create(['department_id' => $this->dept->id, 'employee_no' => 'CIHRM-AI-A']);
    $b = Employee::factory()->create(['department_id' => $this->dept->id, 'employee_no' => 'CIHRM-AI-B']);

    $this->actingAs($this->hr)
        ->postJson(route('ai.employee-summary'), ['employee_id' => $a->id])->assertOk();
    $this->actingAs($this->hr)
        ->postJson(route('ai.employee-summary'), ['employee_id' => $b->id])->assertOk();

    expect($this->fake->calls)->toHaveCount(2);
    expect($this->fake->calls[0]['system'])->toBe($this->fake->calls[1]['system']);
    expect($this->fake->calls[0]['cached'])->toBe($this->fake->calls[1]['cached']);
});

it('reports cache_read_tokens > 0 on the second identical-prefix call', function () {
    $a = Employee::factory()->create(['department_id' => $this->dept->id]);
    $b = Employee::factory()->create(['department_id' => $this->dept->id]);

    $first = $this->actingAs($this->hr)
        ->postJson(route('ai.employee-summary'), ['employee_id' => $a->id])
        ->assertOk()
        ->json('usage');

    $second = $this->actingAs($this->hr)
        ->postJson(route('ai.employee-summary'), ['employee_id' => $b->id])
        ->assertOk()
        ->json('usage');

    expect($first['cache_read_tokens'])->toBe(0);
    expect($first['cache_creation_tokens'])->toBeGreaterThan(0);

    expect($second['cache_read_tokens'])->toBeGreaterThan(0);
    expect($second['cache_creation_tokens'])->toBe(0);
});

it('returns 503 when the LLM provider throws', function () {
    $throwing = new class implements LlmProvider {
        public function complete(string $systemPrompt, string $cachedContext, string $userPrompt): \App\Services\Ai\Contracts\LlmResponse {
            throw new \RuntimeException('upstream is down');
        }
        public function name(): string { return 'broken'; }
    };

    $this->app->instance(LlmProvider::class, $throwing);
    $this->app->forgetInstance(EmployeeSummaryService::class);
    $this->app->forgetInstance(AiAssistantController::class);

    $employee = Employee::factory()->create(['department_id' => $this->dept->id]);

    $this->actingAs($this->hr)
        ->postJson(route('ai.employee-summary'), ['employee_id' => $employee->id])
        ->assertStatus(503)
        ->assertJson(['summary' => null]);
});

it('threads the focus prompt into the user message but not the cached prefix', function () {
    $employee = Employee::factory()->create(['department_id' => $this->dept->id]);

    $this->actingAs($this->hr)
        ->postJson(route('ai.employee-summary'), [
            'employee_id' => $employee->id,
            'prompt'      => 'leave balance and recent absences',
        ])->assertOk();

    $call = $this->fake->calls[0];
    expect($call['user'])->toContain('leave balance and recent absences');
    expect($call['system'])->not->toContain('leave balance and recent absences');
    expect($call['cached'])->not->toContain('leave balance and recent absences');
});

it('validates the request payload', function () {
    $this->actingAs($this->hr)
        ->postJson(route('ai.employee-summary'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['employee_id']);

    $this->actingAs($this->hr)
        ->postJson(route('ai.employee-summary'), ['employee_id' => 999_999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['employee_id']);
});
