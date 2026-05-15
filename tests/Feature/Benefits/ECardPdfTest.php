<?php

declare(strict_types=1);

use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Services\BenefitsService;

use function Pest\Laravel\actingAs;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('downloads an e-card PDF for an active enrolment', function () {
    $emp = Employee::factory()->create();
    $plan = BenefitPlan::create([
        'name' => 'Health Premium', 'code' => 'ECARD-TEST', 'type' => 'health_insurance',
        'monthly_cost' => 300, 'effective_from' => '2026-01-01', 'max_dependants' => 4,
    ]);
    $enrolment = app(BenefitsService::class)->enrol($plan, $emp, new \DateTimeImmutable('2026-02-01'));

    $response = actingAs($emp->user)->get(route('benefits.e-card', $enrolment->id));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});
