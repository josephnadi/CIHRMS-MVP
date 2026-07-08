<?php
declare(strict_types=1);

namespace App\Services\Website;

use App\Enums\JournalSourceType;
use App\Models\ExternalCollection;
use App\Models\FeeGlMapping;
use App\Models\Member;
use App\Services\Finance\PostingService;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CollectionIngestionService
{
    // Cash-basis posting hits clearing + income only (no customer on the JE), so
    // the member is resolved by a direct lookup for drill-down; member_id stays
    // null when unmatched. The AR-side generic-customer fallback lives in
    // MemberMirrorService for a future AR-based posting model.
    public function __construct(
        private readonly PostingService $posting,
    ) {}

    /** Stage + post one normalized collection. Idempotent, fail-soft. */
    public function ingest(array $r): ExternalCollection
    {
        $existing = ExternalCollection::where('source', $r['source'])
            ->where('external_ref', $r['external_ref'])->first();
        if ($existing && $existing->status === ExternalCollection::STATUS_POSTED) {
            return $existing;
        }

        $memberId = $r['external_user_id']
            ? Member::where('external_user_id', $r['external_user_id'])->value('id')
            : null;

        $collection = $existing ?? new ExternalCollection();
        $collection->fill([
            'source' => $r['source'], 'source_id' => $r['source_id'], 'external_ref' => $r['external_ref'],
            'external_user_id' => $r['external_user_id'] ?? null, 'member_id' => $memberId,
            'fee_code' => $r['fee_code'], 'amount' => $r['amount'], 'currency' => $r['currency'],
            'paid_at' => CarbonImmutable::parse($r['paid_at']), 'method' => $r['method'] ?? null,
            'gateway_ref' => $r['gateway_ref'] ?? null, 'payload' => $r,
        ]);

        if (strtoupper((string) $r['currency']) !== 'GHS') {
            return $this->park($collection, ExternalCollection::STATUS_ERROR, "Unsupported currency {$r['currency']}");
        }

        $mapping = FeeGlMapping::forCode($r['fee_code']);
        if (! $mapping) {
            return $this->park($collection, ExternalCollection::STATUS_UNMAPPED, "No GL mapping for {$r['fee_code']}");
        }

        return DB::transaction(function () use ($collection, $mapping, $r) {
            $collection->status = ExternalCollection::STATUS_POSTED;
            $collection->status_note = null;
            $collection->save(); // need the id for the posting source

            $creditAccountId = $mapping->is_deferred
                ? $mapping->deferred_gl_account_id
                : $mapping->income_gl_account_id;

            $doc = new PostingDocument(
                sourceType: JournalSourceType::WebsiteCollection,
                sourceId: $collection->id,
                purpose: 'collection',
                date: CarbonImmutable::parse($r['paid_at'])->toDateString(),
                narration: "Website collection {$r['external_ref']} ({$r['fee_code']})",
                lines: [
                    PostingLine::debit(amount: (float) $r['amount'], accountId: $mapping->clearing_gl_account_id, narration: 'Collections clearing'),
                    PostingLine::credit(amount: (float) $r['amount'], accountId: $creditAccountId, narration: $mapping->label),
                ],
            );

            $entry = $this->posting->post($doc);
            $collection->journal_entry_id = $entry->id;
            $collection->save();

            return $collection;
        });
    }

    private function park(ExternalCollection $c, string $status, string $note): ExternalCollection
    {
        $c->status = $status;
        $c->status_note = $note;
        $c->journal_entry_id = null;
        $c->save();

        return $c;
    }
}
