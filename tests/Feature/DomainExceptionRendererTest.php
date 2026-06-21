<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('renders an uncaught service DomainException as a redirect-with-error, not a 500', function () {
    // VendorController::destroy calls VendorService::archive(), which throws a
    // DomainException when the vendor has open invoices and has NO local catch.
    // The global web renderer must turn that into a friendly redirect-back.
    $u  = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['vendors.manage']]);
    $ap = GlAccount::create(['code' => '2100', 'name' => 'AP', 'type' => 'liability']);
    $vendor = Vendor::create(['code' => 'VEN-OPEN', 'name' => 'Open Co', 'status' => 'active']);
    VendorInvoice::create([
        'reference' => 'API-Z', 'vendor_id' => $vendor->id, 'status' => 'approved',
        'invoice_date' => '2026-05-22', 'subtotal' => 100, 'tax_amount' => 0,
        'total' => 100, 'amount_paid' => 0, 'ap_gl_account_id' => $ap->id, 'created_by' => $u->id,
    ]);

    $res = $this->actingAs($u)->delete(route('finance.vendors.destroy', $vendor));

    $res->assertRedirect();              // NOT a 500
    $res->assertSessionHas('error');     // the guard message is surfaced
    expect(Vendor::find($vendor->id))->not->toBeNull(); // archive did not happen
});
