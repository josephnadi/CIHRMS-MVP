<?php

declare(strict_types=1);

use App\Enums\ArInvoiceStatus;
use App\Enums\JournalEntryStatus;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->svc      = app(ArInvoiceService::class);
    $this->creator  = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->incomeGl = GlAccount::where('code', '4200')->firstOrFail();
    $this->arGl     = GlAccount::where('code', '1200')->firstOrFail();
    $this->customer = Customer::create([
        'code' => 'CUS-T', 'name' => 'Test Customer', 'status' => 'active',
        'default_ar_gl_account_id' => $this->arGl->id,
    ]);
    $this->actingAs($this->creator);
});

function makeDraftAr(): App\Models\ArInvoice
{
    return test()->svc->create([
        'customer_id'  => test()->customer->id,
        'invoice_date' => '2026-05-22',
        'lines' => [[
            'description' => 'Training', 'quantity' => 1, 'unit_price' => 5000,
            'tax_rate' => 0.125, 'gl_account_id' => test()->incomeGl->id,
        ]],
    ], test()->creator);
}

it('edits a draft invoice and keeps the GL balanced (reverse + reissue)', function () {
    $invoice = makeDraftAr();
    $originalJeId = $invoice->accrual_journal_entry_id;

    expect((float) GlAccountBalance::find($this->arGl->id)->balance)->toBe(5625.0);

    $updated = $this->svc->update($invoice, [
        'customer_id'  => $this->customer->id,
        'invoice_date' => '2026-05-22',
        'lines' => [[
            'description' => 'Bigger programme', 'quantity' => 2, 'unit_price' => 4000,
            'tax_rate' => 0.125, 'gl_account_id' => $this->incomeGl->id,
        ]],
    ], $this->creator);

    // New totals: 2 * 4000 = 8000 + 12.5% = 9000.
    expect((float) $updated->subtotal)->toBe(8000.0)
        ->and((float) $updated->total)->toBe(9000.0)
        ->and($updated->lines)->toHaveCount(1)
        ->and($updated->accrual_journal_entry_id)->not->toBe($originalJeId);

    // GL nets to the NEW total: old 5625 reversed, new 9000 posted.
    expect((float) GlAccountBalance::find($this->arGl->id)->balance)->toBe(9000.0)
        ->and((float) GlAccountBalance::find($this->incomeGl->id)->balance)->toBe(9000.0);

    // The new accrual is posted and balanced.
    $je = $updated->accrualJournalEntry;
    expect($je->status)->toBe(JournalEntryStatus::Posted)
        ->and($je->isBalanced())->toBeTrue();
});

it('deletes a draft invoice and reverses its accrual', function () {
    $invoice = makeDraftAr();
    expect((float) GlAccountBalance::find($this->arGl->id)->balance)->toBe(5625.0);

    $this->svc->delete($invoice, $this->creator);

    // Accrual reversed → AR back to zero; invoice soft-deleted.
    expect((float) GlAccountBalance::find($this->arGl->id)->balance)->toBe(0.0);
    expect(App\Models\ArInvoice::find($invoice->id))->toBeNull();
    expect(App\Models\ArInvoice::withTrashed()->find($invoice->id))->not->toBeNull();
});

it('refuses to edit a non-draft invoice', function () {
    $invoice = makeDraftAr();
    $this->svc->submit($invoice);
    $this->svc->approve($invoice->fresh(), $this->approver);

    expect(fn () => $this->svc->update($invoice->fresh(), [
        'customer_id'  => $this->customer->id,
        'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 0, 'gl_account_id' => $this->incomeGl->id]],
    ], $this->creator))->toThrow(DomainException::class);
});

it('refuses to delete a non-draft invoice', function () {
    $invoice = makeDraftAr();
    $this->svc->submit($invoice);
    $this->svc->approve($invoice->fresh(), $this->approver);

    expect(fn () => $this->svc->delete($invoice->fresh(), $this->creator))->toThrow(DomainException::class);
    expect(App\Models\ArInvoice::find($invoice->id))->not->toBeNull();
});

it('renders the print view', function () {
    $invoice = makeDraftAr();
    $viewer  = User::factory()->create(['permissions' => ['ar_invoices.view']]);

    $this->actingAs($viewer)
        ->get(route('finance.ar-invoices.print', $invoice))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/ArInvoices/Print')->has('invoice'));
});

it('edits and deletes a draft over HTTP', function () {
    $invoice = makeDraftAr();
    $officer = User::factory()->create(['permissions' => ['ar_invoices.create', 'ar_invoices.view']]);

    $this->actingAs($officer)
        ->patch(route('finance.ar-invoices.update', $invoice), [
            'customer_id'  => $this->customer->id,
            'invoice_date' => '2026-05-22',
            'lines' => [['description' => 'Edited', 'quantity' => 1, 'unit_price' => 2000, 'tax_rate' => 0, 'gl_account_id' => $this->incomeGl->id]],
        ])
        ->assertRedirect();
    expect((float) $invoice->fresh()->total)->toBe(2000.0);

    $this->actingAs($officer)
        ->delete(route('finance.ar-invoices.destroy', $invoice))
        ->assertRedirect();
    expect(App\Models\ArInvoice::find($invoice->id))->toBeNull();
});
