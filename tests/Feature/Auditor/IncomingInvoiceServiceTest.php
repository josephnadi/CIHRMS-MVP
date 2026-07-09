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

it('auditor vets a submitted invoice', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);

    $this->service->vetAccept($inv->fresh(), $aud, 'looks good');

    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Vetted);
    expect($inv->fresh()->vetted_by)->toBe($aud->id);
});

it('blocks the submitter from vetting their own invoice (dual control)', function () {
    $sub = User::factory()->create(['role' => 'finance_officer']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);

    $this->service->vetAccept($inv->fresh(), $sub);
})->throws(DomainException::class);

it('vetReturn sends it back to returned with a reason', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);

    $this->service->vetReturn($inv->fresh(), $aud, 'missing receipt');

    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Returned);
    expect($inv->fresh()->return_reason)->toBe('missing receipt');
});

it('can resubmit a returned invoice', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetReturn($inv->fresh(), $aud, 'fix it');

    $this->service->submit($inv->fresh(), $sub);
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Submitted);
});

it('ceo approves a vetted invoice', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetAccept($inv->fresh(), $aud);

    $this->service->ceoApprove($inv->fresh(), $ceo);

    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Approved);
    expect($inv->fresh()->approved_by)->toBe($ceo->id);
});

it('ceo cannot approve an un-vetted invoice', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);

    $this->service->ceoApprove($inv->fresh(), $ceo);
})->throws(DomainException::class);

it('ceoReturn sends a vetted invoice back', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetAccept($inv->fresh(), $aud);

    $this->service->ceoReturn($inv->fresh(), $ceo, 'over budget');
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Returned);
});
