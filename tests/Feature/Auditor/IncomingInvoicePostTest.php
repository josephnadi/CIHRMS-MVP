<?php

declare(strict_types=1);

use App\Enums\IncomingInvoiceStatus;
use App\Models\GlAccount;
use App\Models\IncomingInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\IncomingInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    $this->service = app(IncomingInvoiceService::class);
    $this->expense = GlAccount::where('code', '5200')->firstOrFail();
    $this->vendor  = Vendor::create(['code' => 'VEN-P', 'name' => 'Poster', 'status' => 'active']);
});

it('posting an approved invoice promotes it to a VendorInvoice', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $fin = User::factory()->create(['role' => 'finance_officer']);

    $inv = $this->service->create([
        'vendor_name' => 'Poster', 'vendor_invoice_no' => 'BILL-9',
        'invoice_date' => '2026-07-09', 'amount' => 100, 'description' => 'Stuff',
    ], $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetAccept($inv->fresh(), $aud);
    $this->service->ceoApprove($inv->fresh(), $ceo);

    $this->service->post($inv->fresh(), [
        'vendor_id' => $this->vendor->id,
        'lines' => [[
            'description' => 'Stuff', 'quantity' => 1, 'unit_price' => 100,
            'gl_account_id' => $this->expense->id,
        ]],
    ], $fin);

    $inv->refresh();
    expect($inv->status)->toBe(IncomingInvoiceStatus::Posted);
    expect($inv->vendor_invoice_id)->not->toBeNull();
    expect($inv->posted_by)->toBe($fin->id);
});

it('cannot post an invoice that is not approved', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $fin = User::factory()->create(['role' => 'finance_officer']);
    $inv = $this->service->create([
        'vendor_name' => 'Poster', 'invoice_date' => '2026-07-09', 'amount' => 100,
    ], $sub);

    $this->service->post($inv->fresh(), [
        'vendor_id' => $this->vendor->id,
        'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->expense->id]],
    ], $fin);
})->throws(DomainException::class);

it('rejects posting when the line total does not match the CEO-approved amount', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $fin = User::factory()->create(['role' => 'finance_officer']);

    // CEO approves a face value of 100.
    $inv = $this->service->create([
        'vendor_name' => 'Poster', 'invoice_date' => '2026-07-09', 'amount' => 100, 'description' => 'Stuff',
    ], $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetAccept($inv->fresh(), $aud);
    $this->service->ceoApprove($inv->fresh(), $ceo);

    // Finance keys lines totalling 10,000 — must be rejected, and nothing posts.
    expect(fn () => $this->service->post($inv->fresh(), [
        'vendor_id' => $this->vendor->id,
        'lines' => [['description' => 'Stuff', 'quantity' => 1, 'unit_price' => 10000, 'gl_account_id' => $this->expense->id]],
    ], $fin))->toThrow(DomainException::class);

    $inv->refresh();
    expect($inv->status)->toBe(IncomingInvoiceStatus::Approved);
    expect($inv->vendor_invoice_id)->toBeNull();
});

it('accepts a posted line total that matches the approved amount including tax', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $fin = User::factory()->create(['role' => 'finance_officer']);

    // Face value 110 = 100 net + 10% tax.
    $inv = $this->service->create([
        'vendor_name' => 'Poster', 'invoice_date' => '2026-07-09', 'amount' => 110, 'description' => 'Stuff',
    ], $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetAccept($inv->fresh(), $aud);
    $this->service->ceoApprove($inv->fresh(), $ceo);

    $this->service->post($inv->fresh(), [
        'vendor_id' => $this->vendor->id,
        'lines' => [['description' => 'Stuff', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0.1, 'gl_account_id' => $this->expense->id]],
    ], $fin);

    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Posted);
});
