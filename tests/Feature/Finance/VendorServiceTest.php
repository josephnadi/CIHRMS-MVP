<?php

declare(strict_types=1);

use App\Enums\VendorStatus;
use App\Models\GlAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Services\Finance\VendorService;

beforeEach(function () {
    $this->svc = app(VendorService::class);
});

it('creates a vendor with a default active status', function () {
    $v = $this->svc->create(['code' => 'VEN-AAA', 'name' => 'Acme Co']);
    expect($v->status)->toBe(VendorStatus::Active);
    expect($v->code)->toBe('VEN-AAA');
});

it('updates a vendor', function () {
    $v = $this->svc->create(['code' => 'VEN-AAA', 'name' => 'Acme Co']);
    $u = $this->svc->update($v, ['name' => 'Acme Holdings']);
    expect($u->name)->toBe('Acme Holdings');
});

it('archives a vendor (soft delete)', function () {
    $v = $this->svc->create(['code' => 'VEN-AAA', 'name' => 'Acme Co']);
    $this->svc->archive($v);
    expect(Vendor::find($v->id))->toBeNull();
    expect(Vendor::withTrashed()->find($v->id)->trashed())->toBeTrue();
});

it('refuses to archive a vendor with non-cancelled invoices', function () {
    $u  = User::factory()->create();
    $ap = GlAccount::create(['code' => '2100', 'name' => 'AP', 'type' => 'liability']);
    $v  = $this->svc->create(['code' => 'VEN-OPEN', 'name' => 'Open']);

    VendorInvoice::create([
        'reference' => 'API-X', 'vendor_id' => $v->id, 'status' => 'draft',
        'invoice_date' => '2026-05-22', 'subtotal' => 100, 'tax_amount' => 0,
        'total' => 100, 'amount_paid' => 0, 'ap_gl_account_id' => $ap->id, 'created_by' => $u->id,
    ]);

    expect(fn () => $this->svc->archive($v->fresh()))
        ->toThrow(\DomainException::class, 'open invoices');
});

it('archive allows when all invoices are cancelled', function () {
    $u  = User::factory()->create();
    $ap = GlAccount::create(['code' => '2100', 'name' => 'AP', 'type' => 'liability']);
    $v  = $this->svc->create(['code' => 'VEN-CXL', 'name' => 'Cancelled']);

    VendorInvoice::create([
        'reference' => 'API-C', 'vendor_id' => $v->id, 'status' => 'cancelled',
        'invoice_date' => '2026-05-22', 'subtotal' => 100, 'tax_amount' => 0,
        'total' => 100, 'amount_paid' => 0, 'ap_gl_account_id' => $ap->id, 'created_by' => $u->id,
    ]);

    $this->svc->archive($v->fresh());
    expect(Vendor::find($v->id))->toBeNull();
});

it('list filters by status and search', function () {
    $this->svc->create(['code' => 'V-A', 'name' => 'Acme',     'status' => 'active']);
    $this->svc->create(['code' => 'V-B', 'name' => 'Beta Ltd', 'status' => 'inactive']);

    expect($this->svc->list(['status' => 'active'])->pluck('name')->all())->toBe(['Acme']);
    expect($this->svc->list(['search' => 'beta'])->pluck('code')->all())->toBe(['V-B']);
});
