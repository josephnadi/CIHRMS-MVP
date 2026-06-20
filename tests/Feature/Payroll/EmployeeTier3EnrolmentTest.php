<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\PensionTrustee;

it('stores a Tier-3 election with a trustee', function () {
    $trustee = PensionTrustee::create([
        'name'                => 'Acme Master Trust',
        'npra_license_number' => 'NPRA-T3-001',
        'is_active'           => true,
    ]);
    $employee = Employee::factory()->create(['tier3_rate' => 0.05, 'tier3_trustee_id' => $trustee->id]);

    expect((float) $employee->fresh()->tier3_rate)->toBe(0.05)
        ->and($employee->fresh()->tier3Trustee->id)->toBe($trustee->id);
});

it('defaults Tier-3 rate to zero', function () {
    expect((float) Employee::factory()->create()->tier3_rate)->toBe(0.0);
});
