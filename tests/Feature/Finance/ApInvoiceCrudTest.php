<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Services\Finance\VendorInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->svc       = app(VendorInvoiceService::class);
    $this->creator   = User::factory()->create();
    $this->approver  = User::factory()->create();
    $this->expenseGl = GlAccount::where('code', '5200')->firstOrFail();
    $this->apGl      = GlAccount::where('code', '2100')->firstOrFail();
    $this->vendor    = Vendor::create([
        'code' => 'VEN-T', 'name' => 'Test Vendor', 'status' => 'active',
        'default_ap_gl_account_id' => $this->apGl->id,
    ]);
    $this->actingAs($this->creator);
});

function makeDraftAp(): App\Models\VendorInvoice
{
    return test()->svc->create([
        'vendor_id'    => test()->vendor->id,
        'invoice_date' => '2026-05-22',
        'lines' => [[
            'description' => 'Supplies', 'quantity' => 1, 'unit_price' => 800,
            'tax_rate' => 0.125, 'gl_account_id' => test()->expenseGl->id,
        ]],
    ], test()->creator);
}

it('edits a draft vendor invoice and keeps the GL balanced (reverse + reissue)', function () {
    $invoice = makeDraftAp();
    $originalJeId = $invoice->accrual_journal_entry_id;
    expect((float) GlAccountBalance::find($this->apGl->id)->balance)->toBe(900.0);

    $updated = $this->svc->update($invoice, [
        'vendor_id'    => $this->vendor->id,
        'invoice_date' => '2026-05-22',
        'lines' => [[
            'description' => 'More supplies', 'quantity' => 4, 'unit_price' => 1000,
            'tax_rate' => 0.125, 'gl_account_id' => $this->expenseGl->id,
        ]],
    ], $this->creator);

    // New totals: 4 * 1000 = 4000 + 12.5% = 4500.
    expect((float) $updated->total)->toBe(4500.0)
        ->and($updated->accrual_journal_entry_id)->not->toBe($originalJeId);

    expect((float) GlAccountBalance::find($this->apGl->id)->balance)->toBe(4500.0)
        ->and((float) GlAccountBalance::find($this->expenseGl->id)->balance)->toBe(4500.0);

    $je = $updated->accrualJournalEntry;
    expect($je->status)->toBe(JournalEntryStatus::Posted)->and($je->isBalanced())->toBeTrue();
});

it('deletes a draft vendor invoice and reverses its accrual', function () {
    $invoice = makeDraftAp();
    expect((float) GlAccountBalance::find($this->apGl->id)->balance)->toBe(900.0);

    $this->svc->delete($invoice, $this->creator);

    expect((float) GlAccountBalance::find($this->apGl->id)->balance)->toBe(0.0);
    expect(VendorInvoice::find($invoice->id))->toBeNull();
    expect(VendorInvoice::withTrashed()->find($invoice->id))->not->toBeNull();
});

it('refuses to edit or delete a non-draft vendor invoice', function () {
    $invoice = makeDraftAp();
    $this->svc->submit($invoice);
    $this->svc->approve($invoice->fresh(), $this->approver);

    expect(fn () => $this->svc->update($invoice->fresh(), [
        'vendor_id' => $this->vendor->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 0, 'gl_account_id' => $this->expenseGl->id]],
    ], $this->creator))->toThrow(DomainException::class);

    expect(fn () => $this->svc->delete($invoice->fresh(), $this->creator))->toThrow(DomainException::class);
});

it('renders the print view', function () {
    $invoice = makeDraftAp();
    $viewer  = User::factory()->create(['permissions' => ['ap_invoices.view']]);

    $this->actingAs($viewer)
        ->get(route('finance.ap-invoices.print', $invoice))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/ApInvoices/Print')->has('invoice'));
});

it('edits and deletes a draft over HTTP', function () {
    $invoice = makeDraftAp();
    $officer = User::factory()->create(['permissions' => ['ap_invoices.create', 'ap_invoices.view']]);

    $this->actingAs($officer)
        ->patch(route('finance.ap-invoices.update', $invoice), [
            'vendor_id'    => $this->vendor->id,
            'invoice_date' => '2026-05-22',
            'lines' => [['description' => 'Edited', 'quantity' => 1, 'unit_price' => 1500, 'tax_rate' => 0, 'gl_account_id' => $this->expenseGl->id]],
        ])
        ->assertRedirect();
    expect((float) $invoice->fresh()->total)->toBe(1500.0);

    $this->actingAs($officer)
        ->delete(route('finance.ap-invoices.destroy', $invoice))
        ->assertRedirect();
    expect(VendorInvoice::find($invoice->id))->toBeNull();
});
