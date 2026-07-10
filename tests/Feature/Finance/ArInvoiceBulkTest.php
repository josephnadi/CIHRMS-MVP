<?php

declare(strict_types=1);

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->income = GlAccount::where('code', '4200')->firstOrFail();
    $arGl         = GlAccount::where('code', '1200')->firstOrFail();
    $this->user   = User::factory()->create(['permissions' => ['ar_invoices.create']]);
    $this->customers = collect(['A', 'B', 'C'])->map(fn ($c) => Customer::create([
        'code' => "CUS-{$c}", 'name' => "Customer {$c}", 'status' => 'active',
        'default_ar_gl_account_id' => $arGl->id,
    ]));
});

it('creates one draft invoice per selected customer, sharing the lines', function () {
    $ids = $this->customers->pluck('id')->all();

    $this->actingAs($this->user)
        ->post(route('finance.ar-invoices.bulk-store'), [
            'customer_ids' => $ids,
            'invoice_date' => '2026-07-10',
            'lines' => [[
                'description' => 'Annual membership', 'quantity' => 1, 'unit_price' => 500,
                'tax_rate' => 0, 'gl_account_id' => $this->income->id,
            ]],
        ])
        ->assertRedirect();

    expect(ArInvoice::count())->toBe(3);
    foreach ($ids as $id) {
        expect(ArInvoice::where('customer_id', $id)->count())->toBe(1);
    }
    // Every invoice carries the shared line.
    expect(ArInvoice::query()->withCount('lines')->get()->every(fn ($inv) => $inv->lines_count === 1))->toBeTrue();
});

it('rejects a bulk create with no customers selected', function () {
    $this->actingAs($this->user)
        ->post(route('finance.ar-invoices.bulk-store'), [
            'invoice_date' => '2026-07-10',
            'lines' => [[
                'description' => 'x', 'quantity' => 1, 'unit_price' => 500,
                'tax_rate' => 0, 'gl_account_id' => $this->income->id,
            ]],
        ])
        ->assertSessionHasErrors('customer_ids');

    expect(ArInvoice::count())->toBe(0);
});
