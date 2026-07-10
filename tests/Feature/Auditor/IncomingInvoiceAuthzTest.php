<?php

declare(strict_types=1);

use App\Enums\IncomingInvoiceStatus;
use App\Models\GlAccount;
use App\Models\IncomingInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Services\Finance\IncomingInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    $this->service = app(IncomingInvoiceService::class);
});

function authzData(array $o = []): array
{
    return array_merge([
        'vendor_name' => 'Acme Co', 'vendor_invoice_no' => 'BILL-1',
        'invoice_date' => '2026-07-09', 'amount' => 100, 'description' => 'Toner',
    ], $o);
}

// ── Segregation of duties ──────────────────────────────────────────────

it('blocks the vetter from also CEO-approving the same invoice (dual control)', function () {
    $sub  = User::factory()->create(['role' => 'dept_head']);
    $dual = User::factory()->create(['role' => 'auditor']); // vets, then tries to approve
    $inv  = $this->service->create(authzData(), $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetAccept($inv->fresh(), $dual);

    $this->service->ceoApprove($inv->fresh(), $dual);
})->throws(DomainException::class, 'Dual-control');

it('blocks the submitter (distinct from the creator) from vetting', function () {
    $creator   = User::factory()->create(['role' => 'dept_head']);
    $submitter = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(authzData(), $creator);
    $this->service->submit($inv->fresh(), $submitter); // submitted_by = submitter

    $this->service->vetAccept($inv->fresh(), $submitter);
})->throws(DomainException::class, 'Dual-control');

// ── Double-post ────────────────────────────────────────────────────────

it('cannot post the same invoice twice — one VendorInvoice only', function () {
    $vendor  = Vendor::create(['code' => 'VEN-P', 'name' => 'Poster', 'status' => 'active']);
    $expense = GlAccount::where('code', '5200')->firstOrFail();
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $fin = User::factory()->create(['role' => 'finance_officer']);

    $inv = $this->service->create(authzData(['amount' => 100]), $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetAccept($inv->fresh(), $aud);
    $this->service->ceoApprove($inv->fresh(), $ceo);

    $payload = ['vendor_id' => $vendor->id, 'lines' => [[
        'description' => 'Stuff', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id,
    ]]];

    $this->service->post($inv->fresh(), $payload, $fin);
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Posted);

    // Second post is rejected by the status guard; the ledger is not double-hit.
    expect(fn () => $this->service->post($inv->fresh(), $payload, $fin))->toThrow(DomainException::class);
    expect(VendorInvoice::count())->toBe(1);
});

// ── Object-level authorization (IDOR) ──────────────────────────────────

it('forbids a department user from viewing another user’s invoice', function () {
    $creator = User::factory()->create(['role' => 'dept_head']);
    $other   = User::factory()->create(['role' => 'dept_head']); // has view, but not creator/central
    $auditor = User::factory()->create(['role' => 'auditor']);   // central processor — sees all
    $inv = $this->service->create(authzData(), $creator);

    $this->actingAs($other)->get(route('auditor.incoming-invoices.show', $inv->id))->assertForbidden();
    $this->actingAs($creator)->get(route('auditor.incoming-invoices.show', $inv->id))->assertOk();
    $this->actingAs($auditor)->get(route('auditor.incoming-invoices.show', $inv->id))->assertOk();
});

it('forbids a department user from editing another user’s invoice', function () {
    $creator = User::factory()->create(['role' => 'dept_head']);
    $other   = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(authzData(), $creator);

    $this->actingAs($other)
        ->patch(route('auditor.incoming-invoices.update', $inv->id), authzData(['amount' => 999999]))
        ->assertForbidden();

    expect((float) $inv->fresh()->amount)->toBe(100.0); // unchanged
});

it('scopes the index list to the viewer’s own invoices for department users', function () {
    $creator = User::factory()->create(['role' => 'dept_head']);
    $other   = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(authzData(['vendor_name' => 'ScopedVendor']), $creator);

    $this->actingAs($other)->get(route('auditor.incoming-invoices.index'))
        ->assertOk()
        ->assertDontSee('ScopedVendor');
    $this->actingAs($creator)->get(route('auditor.incoming-invoices.index'))
        ->assertOk()
        ->assertSee('ScopedVendor');
});
