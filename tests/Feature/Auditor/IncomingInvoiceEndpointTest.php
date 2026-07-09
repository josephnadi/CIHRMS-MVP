<?php

declare(strict_types=1);

use App\Enums\IncomingInvoiceStatus;
use App\Models\GlAccount;
use App\Models\IncomingInvoice;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    Storage::fake('local');
    $this->expense = GlAccount::where('code', '5200')->firstOrFail();
    $this->vendor  = Vendor::create(['code' => 'VEN-E', 'name' => 'E', 'status' => 'active']);
});

it('dept_head can list, employee cannot', function () {
    $this->actingAs(User::factory()->create(['role' => 'dept_head']))
        ->get('/auditor/incoming-invoices')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Auditor/IncomingInvoices/Index'));

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->get('/auditor/incoming-invoices')->assertForbidden();
});

it('stores an invoice with an attachment', function () {
    $u = User::factory()->create(['role' => 'dept_head']);
    $this->actingAs($u)->post('/auditor/incoming-invoices', [
        'vendor_name' => 'Acme', 'invoice_date' => '2026-07-09', 'amount' => 250,
        'description' => 'Paper',
        'attachments' => [UploadedFile::fake()->create('bill.pdf', 40, 'application/pdf')],
    ])->assertRedirect();

    $inv = IncomingInvoice::latest()->first();
    expect($inv->attachments()->count())->toBe(1);
    expect($inv->status)->toBe(IncomingInvoiceStatus::Draft);
});

it('walks the full lifecycle over HTTP', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $fin = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($sub)->post('/auditor/incoming-invoices', [
        'vendor_name' => 'Acme', 'invoice_date' => '2026-07-09', 'amount' => 100, 'description' => 'x',
    ]);
    $inv = IncomingInvoice::latest()->first();

    $this->actingAs($sub)->post("/auditor/incoming-invoices/{$inv->id}/submit")->assertRedirect();
    $this->actingAs($aud)->post("/auditor/incoming-invoices/{$inv->id}/vet")->assertRedirect();
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Vetted);

    $this->actingAs($ceo)->post("/auditor/incoming-invoices/{$inv->id}/approve")->assertRedirect();
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Approved);

    $this->actingAs($fin)->post("/auditor/incoming-invoices/{$inv->id}/post", [
        'vendor_id' => $this->vendor->id,
        'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->expense->id]],
    ])->assertRedirect();
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Posted);
    expect($inv->fresh()->vendor_invoice_id)->not->toBeNull();
});

it('auditor return requires a reason', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($sub)->post('/auditor/incoming-invoices', [
        'vendor_name' => 'Acme', 'invoice_date' => '2026-07-09', 'amount' => 100,
    ]);
    $inv = IncomingInvoice::latest()->first();
    $this->actingAs($sub)->post("/auditor/incoming-invoices/{$inv->id}/submit");

    $this->actingAs($aud)->post("/auditor/incoming-invoices/{$inv->id}/vet-return", [])
        ->assertSessionHasErrors(['reason']);
});
