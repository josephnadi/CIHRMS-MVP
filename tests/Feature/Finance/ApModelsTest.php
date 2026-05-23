<?php

declare(strict_types=1);

use App\Enums\ApPaymentStatus;
use App\Enums\VendorInvoiceStatus;
use App\Models\ApPayment;
use App\Models\ApPaymentInvoiceAllocation;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceLine;

it('creates a vendor invoice with status enum cast + decimals', function () {
    $u  = User::factory()->create();
    $v  = Vendor::factory()->create();
    $gl = GlAccount::create(['code' => '2100', 'name' => 'AP', 'type' => 'liability']);

    $inv = VendorInvoice::create([
        'reference'        => 'API-2026-0001',
        'vendor_id'        => $v->id,
        'vendor_invoice_no'=> 'INV-001',
        'status'           => VendorInvoiceStatus::Draft->value,
        'invoice_date'     => '2026-05-22',
        'subtotal'         => 1000,
        'tax_amount'       => 125,
        'total'            => 1125,
        'amount_paid'      => 0,
        'ap_gl_account_id' => $gl->id,
        'created_by'       => $u->id,
    ]);

    expect($inv->status)->toBe(VendorInvoiceStatus::Draft);
    expect((float) $inv->total)->toBe(1125.0);
    expect($inv->vendor->id)->toBe($v->id);
});

it('VendorInvoice.outstandingAmount = total - amount_paid', function () {
    $u  = User::factory()->create();
    $v  = Vendor::factory()->create();
    $gl = GlAccount::create(['code' => '2100', 'name' => 'AP', 'type' => 'liability']);

    $inv = VendorInvoice::create([
        'reference' => 'API-X', 'vendor_id' => $v->id,
        'status' => 'approved', 'invoice_date' => '2026-05-22',
        'subtotal' => 800, 'tax_amount' => 0, 'total' => 800, 'amount_paid' => 300,
        'ap_gl_account_id' => $gl->id, 'created_by' => $u->id,
    ]);

    expect($inv->outstandingAmount())->toBe(500.0);
});

it('VendorInvoiceLine cascades when invoice is deleted', function () {
    $u   = User::factory()->create();
    $v   = Vendor::factory()->create();
    $gl  = GlAccount::create(['code' => '2100', 'name' => 'AP', 'type' => 'liability']);
    $exp = GlAccount::create(['code' => '5100', 'name' => 'Exp', 'type' => 'expense']);

    $inv = VendorInvoice::create([
        'reference' => 'API-DEL', 'vendor_id' => $v->id, 'status' => 'draft',
        'invoice_date' => '2026-05-22', 'subtotal' => 100, 'tax_amount' => 0,
        'total' => 100, 'amount_paid' => 0, 'ap_gl_account_id' => $gl->id, 'created_by' => $u->id,
    ]);
    VendorInvoiceLine::create([
        'vendor_invoice_id' => $inv->id, 'line_no' => 1, 'description' => 'X',
        'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'gl_account_id' => $exp->id,
    ]);

    expect($inv->lines)->toHaveCount(1);

    $inv->forceDelete();
    expect(VendorInvoiceLine::where('vendor_invoice_id', $inv->id)->count())->toBe(0);
});

it('ApPayment casts status enum + decimals + dates', function () {
    $u    = User::factory()->create();
    $v    = Vendor::factory()->create();
    $gl   = GlAccount::create(['code' => '1100', 'name' => 'Bank', 'type' => 'asset']);
    $bank = OrgBankAccount::create([
        'gl_account_id' => $gl->id, 'bank_name' => 'B', 'account_name' => 'X',
        'account_number' => '999', 'purpose' => 'operating',
    ]);

    $pay = ApPayment::create([
        'reference' => 'APP-0001', 'vendor_id' => $v->id, 'status' => 'pending',
        'payment_date' => '2026-05-22', 'amount' => 500.00,
        'org_bank_account_id' => $bank->id, 'created_by' => $u->id,
    ]);

    expect($pay->status)->toBe(ApPaymentStatus::Pending);
    expect((float) $pay->amount)->toBe(500.0);
    expect($pay->payment_date->format('Y-m-d'))->toBe('2026-05-22');
});

it('ApPaymentInvoiceAllocation links payment to invoice', function () {
    $u    = User::factory()->create();
    $v    = Vendor::factory()->create();
    $apGl = GlAccount::create(['code' => '2100', 'name' => 'AP', 'type' => 'liability']);
    $cash = GlAccount::create(['code' => '1100', 'name' => 'Bank', 'type' => 'asset']);
    $bank = OrgBankAccount::create([
        'gl_account_id' => $cash->id, 'bank_name' => 'B', 'account_name' => 'X',
        'account_number' => '999', 'purpose' => 'operating',
    ]);

    $inv = VendorInvoice::create([
        'reference' => 'API-A', 'vendor_id' => $v->id, 'status' => 'approved',
        'invoice_date' => '2026-05-22', 'subtotal' => 500, 'tax_amount' => 0,
        'total' => 500, 'amount_paid' => 0, 'ap_gl_account_id' => $apGl->id, 'created_by' => $u->id,
    ]);
    $pay = ApPayment::create([
        'reference' => 'APP-A', 'vendor_id' => $v->id, 'status' => 'pending',
        'payment_date' => '2026-05-22', 'amount' => 500,
        'org_bank_account_id' => $bank->id, 'created_by' => $u->id,
    ]);

    $alloc = ApPaymentInvoiceAllocation::create([
        'ap_payment_id' => $pay->id, 'vendor_invoice_id' => $inv->id, 'allocated_amount' => 500,
    ]);

    expect($alloc->payment->id)->toBe($pay->id);
    expect($alloc->invoice->id)->toBe($inv->id);
    expect($pay->fresh()->allocations)->toHaveCount(1);
});
