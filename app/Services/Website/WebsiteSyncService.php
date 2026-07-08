<?php
declare(strict_types=1);

namespace App\Services\Website;

use App\Models\ExternalCollection;
use App\Models\SyncState;

class WebsiteSyncService
{
    public function __construct(
        private readonly WebsiteFeedClient $client,
        private readonly MemberMirrorService $mirror,
        private readonly CollectionIngestionService $ingestion,
    ) {}

    public function sync(): array
    {
        $report = ['members' => 0, 'pulled' => 0, 'posted' => 0, 'unmapped' => 0, 'error' => 0, 'flagged' => 0, 'skipped' => 0];

        // 1. Members
        $mState = SyncState::for('members');
        $cursor = null;
        do {
            $requestedCursor = $cursor;
            $page = $this->client->members($mState->watermark, $cursor, 200);
            foreach ($page['data'] as $rec) {
                $this->mirror->upsert($rec);
                $report['members']++;
            }
            if ($page['next_cursor'] !== null && $page['next_cursor'] === $requestedCursor) {
                \Illuminate\Support\Facades\Log::warning('WebsiteSyncService: members feed returned a non-advancing cursor; aborting pagination to avoid an infinite loop.', [
                    'cursor' => $requestedCursor,
                ]);
                break;
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        $mState->update(['last_run_at' => now()]);

        // 2. Collections
        $cState = SyncState::for('collections');
        $cursor = null;
        do {
            $requestedCursor = $cursor;
            $page = $this->client->collections($cState->watermark, $cursor, 200);
            foreach ($page['data'] as $rec) {
                $report['pulled']++;

                try {
                    $before = ExternalCollection::where('source', $rec['source'])
                        ->where('external_ref', $rec['external_ref'])
                        ->where('status', ExternalCollection::STATUS_POSTED)->exists();

                    $c = $this->ingestion->ingest($rec);

                    if ($before) { $report['skipped']++; continue; }
                    match ($c->status) {
                        ExternalCollection::STATUS_POSTED   => $report['posted']++,
                        ExternalCollection::STATUS_UNMAPPED => $report['unmapped']++,
                        ExternalCollection::STATUS_ERROR    => $report['error']++,
                        ExternalCollection::STATUS_FLAGGED  => $report['flagged']++,
                        default => null,
                    };
                } catch (\Throwable $e) {
                    // A single malformed row (e.g. missing source/external_ref/
                    // fee_code so ingest() can't even key it) must never abort
                    // the batch — log it, tally it as an error, and move on.
                    \Illuminate\Support\Facades\Log::warning('WebsiteSyncService: failed to ingest a collection row; skipping.', [
                        'error' => $e->getMessage(),
                        'record' => $rec,
                    ]);
                    $report['error']++;
                    continue;
                }
            }
            if ($page['next_cursor'] !== null && $page['next_cursor'] === $requestedCursor) {
                \Illuminate\Support\Facades\Log::warning('WebsiteSyncService: collections feed returned a non-advancing cursor; aborting pagination to avoid an infinite loop.', [
                    'cursor' => $requestedCursor,
                ]);
                break;
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        $cState->update(['last_run_at' => now()]);

        // TODO(perf): watermark stays null (full re-pull) in v1; idempotency via
        // external_collections uniqueness makes re-posting a no-op. Advance
        // `watermark` to the max paid_at/updated_at once feed volume warrants it.

        return $report;
    }
}
