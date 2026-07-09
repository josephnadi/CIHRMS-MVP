<?php

declare(strict_types=1);

use App\Enums\IncomingInvoiceStatus;
use App\Events\IncomingInvoiceSubmitted;
use App\Models\IncomingInvoice;
use App\Models\User;
use App\Services\Finance\IncomingInvoiceService;
use Illuminate\Support\Facades\Event;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    $this->service = app(IncomingInvoiceService::class);
});

function makeData(array $overrides = []): array
{
    return array_merge([
        'vendor_name'  => 'Acme Co',
        'vendor_invoice_no' => 'BILL-1',
        'invoice_date' => '2026-07-09',
        'amount'       => 1500,
        'description'  => 'Toner',
    ], $overrides);
}

it('creates a draft with a sequenced reference and a created event', function () {
    $u = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(makeData(), $u);

    expect($inv->status)->toBe(IncomingInvoiceStatus::Draft);
    expect($inv->reference)->toStartWith('INV-');
    expect($inv->created_by)->toBe($u->id);
    expect($inv->events()->where('action', 'created')->exists())->toBeTrue();
});

it('records attachments passed in create', function () {
    $u = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(makeData(['attachments' => [
        ['path' => 'incoming-invoices/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10],
    ]]), $u);

    expect($inv->attachments()->count())->toBe(1);
});

it('submit moves draft to submitted and fires the event', function () {
    Event::fake([IncomingInvoiceSubmitted::class]);
    $u = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(makeData(), $u);

    $this->service->submit($inv, $u);

    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Submitted);
    expect($inv->fresh()->submitted_by)->toBe($u->id);
    Event::assertDispatched(IncomingInvoiceSubmitted::class);
});

it('update refuses a submitted invoice', function () {
    $u = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(makeData(), $u);
    $this->service->submit($inv, $u);

    $this->service->update($inv->fresh(), makeData(['amount' => 99]), $u);
})->throws(DomainException::class);
