<?php
declare(strict_types=1);

use App\Models\ExternalCollection;
use App\Models\User;

it('renders the reconciliation dashboard with summary + unresolved worklist', function () {
    ExternalCollection::create(['source' => 's', 'source_id' => 1, 'external_ref' => 'A', 'fee_code' => 'exam',
        'amount' => 200, 'currency' => 'GHS', 'paid_at' => now(), 'status' => 'posted']);
    ExternalCollection::create(['source' => 's', 'source_id' => 2, 'external_ref' => 'B', 'fee_code' => 'mystery',
        'amount' => 50, 'currency' => 'GHS', 'paid_at' => now(), 'status' => 'unmapped', 'status_note' => 'no map']);

    $user = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['finance.reports']]);

    $this->actingAs($user)->get(route('finance.reconciliation'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/CollectionReconciliation/Index')
            ->has('summary')
            ->has('unresolved', 1));
});
