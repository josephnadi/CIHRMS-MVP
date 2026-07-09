<?php
declare(strict_types=1);

use App\Models\ExternalCollection;
use Illuminate\Database\QueryException;

it('persists a staged collection and casts payload', function () {
    $c = ExternalCollection::create([
        'source' => 'member_fee_payment', 'source_id' => 10, 'external_ref' => 'TXN-1',
        'fee_code' => 'member.subscription', 'amount' => 350, 'currency' => 'GHS',
        'paid_at' => now(), 'payload' => ['a' => 1], 'status' => ExternalCollection::STATUS_POSTED,
    ]);
    expect($c->payload)->toBe(['a' => 1])->and($c->status)->toBe('posted');
});

it('rejects a duplicate (source, external_ref)', function () {
    $row = fn () => ExternalCollection::create([
        'source' => 'member_fee_payment', 'source_id' => 10, 'external_ref' => 'TXN-1',
        'fee_code' => 'member.subscription', 'amount' => 350, 'currency' => 'GHS',
        'paid_at' => now(), 'status' => ExternalCollection::STATUS_POSTED,
    ]);
    $row();
    expect($row)->toThrow(QueryException::class);
});
