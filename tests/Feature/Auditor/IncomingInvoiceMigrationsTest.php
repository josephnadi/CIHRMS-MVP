<?php

declare(strict_types=1);

use App\Enums\IncomingInvoiceStatus;
use App\Models\IncomingInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('creates the three tables', function () {
    expect(Schema::hasTable('incoming_invoices'))->toBeTrue();
    expect(Schema::hasTable('incoming_invoice_attachments'))->toBeTrue();
    expect(Schema::hasTable('incoming_invoice_events'))->toBeTrue();
    expect(Schema::hasColumns('incoming_invoices', [
        'reference', 'status', 'department_id', 'vendor_name', 'amount',
        'submitted_by', 'vetted_by', 'approved_by', 'returned_by',
        'posted_by', 'vendor_invoice_id', 'created_by',
    ]))->toBeTrue();
});

it('casts status to the enum and defaults to draft', function () {
    $u = User::factory()->create();
    $inv = IncomingInvoice::create([
        'reference'   => 'INV-TEST-1',
        'vendor_name' => 'Acme Co',
        'invoice_date'=> '2026-07-09',
        'amount'      => 1200.50,
        'description' => 'Toner cartridges',
        'created_by'  => $u->id,
    ]);
    expect($inv->status)->toBe(IncomingInvoiceStatus::Draft);
    expect((float) $inv->amount)->toBe(1200.50);
});
