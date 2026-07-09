<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\JournalSourceType;
use App\Models\ExternalCollection;
use App\Models\FeeGlMapping;
use App\Models\RevenueRecognitionEntry;
use App\Models\RevenueRecognitionSchedule;
use App\Models\User;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deferred income (Subscription in Advance, Note 10). Straight-lines a deferred
 * posting from the deferred liability (2400) to income over `months`, starting
 * on the payment date. The monthly run posts DR 2400 / CR income for each due
 * entry.
 */
class RevenueRecognitionService
{
    public function __construct(private readonly PostingService $posting) {}

    /**
     * Create the release schedule for a deferred collection (idempotent on the
     * collection). Called inside the ingestion transaction when a subscription
     * is deferred to 2400. The period starts on the collection's payment date.
     */
    public function scheduleForCollection(ExternalCollection $collection, FeeGlMapping $mapping): RevenueRecognitionSchedule
    {
        $existing = RevenueRecognitionSchedule::where('source_type', 'external_collection')
            ->where('source_id', $collection->id)->first();
        if ($existing) {
            return $existing;
        }

        $total  = round((float) $collection->amount, 2);
        $months = max(1, (int) ($mapping->recognition_months ?? 1));
        $start  = CarbonImmutable::parse($collection->paid_at)->startOfMonth();

        $schedule = RevenueRecognitionSchedule::create([
            'source_type'            => 'external_collection',
            'source_id'              => $collection->id,
            'member_id'              => $collection->member_id,
            'income_gl_account_id'   => $mapping->income_gl_account_id,
            'deferred_gl_account_id' => $mapping->deferred_gl_account_id,
            'total_amount'           => $total,
            'months'                 => $months,
            'start_date'             => CarbonImmutable::parse($collection->paid_at)->toDateString(),
            'recognized_total'       => 0,
            'status'                 => RevenueRecognitionSchedule::STATUS_ACTIVE,
        ]);

        // Even split; the final tranche absorbs the rounding remainder so the
        // entries always sum to exactly the total.
        $per       = round($total / $months, 2);
        $allocated = 0.0;
        for ($i = 0; $i < $months; $i++) {
            $amount = ($i === $months - 1) ? round($total - $allocated, 2) : $per;
            $allocated = round($allocated + $amount, 2);

            RevenueRecognitionEntry::create([
                'schedule_id'  => $schedule->id,
                'period_month' => $start->addMonths($i)->format('Y-m'),
                'amount'       => $amount,
                'status'       => RevenueRecognitionEntry::STATUS_PENDING,
            ]);
        }

        return $schedule->load('entries');
    }

    /**
     * Recognise every pending entry due on or before $periodMonth ('YYYY-MM').
     * Each entry posts DR deferred / CR income dated at its own month end, so a
     * catch-up run still books revenue to the correct period. Idempotent per
     * entry (PostingService dedupes on the entry id); fail-soft per entry.
     *
     * @return array{recognized:int, amount:float, skipped:int, errors:int, completed:int}
     */
    public function recognizeForMonth(string $periodMonth, ?User $actor = null): array
    {
        $report = ['recognized' => 0, 'amount' => 0.0, 'skipped' => 0, 'errors' => 0, 'completed' => 0];

        $entries = RevenueRecognitionEntry::query()
            ->where('status', RevenueRecognitionEntry::STATUS_PENDING)
            ->where('period_month', '<=', $periodMonth)
            ->whereHas('schedule', fn ($q) => $q->where('status', RevenueRecognitionSchedule::STATUS_ACTIVE))
            ->with('schedule')
            ->orderBy('period_month')
            ->get();

        foreach ($entries as $entry) {
            $schedule = $entry->schedule;
            if (! $schedule) {
                $report['skipped']++;
                continue;
            }

            try {
                DB::transaction(function () use ($entry, $schedule, $actor, &$report) {
                    $date = CarbonImmutable::createFromFormat('Y-m', $entry->period_month)->endOfMonth()->toDateString();

                    $doc = new PostingDocument(
                        sourceType: JournalSourceType::RevenueRecognition,
                        sourceId: $entry->id,
                        purpose: 'recognition',
                        date: $date,
                        narration: "Revenue recognition {$entry->period_month} (schedule #{$schedule->id})",
                        lines: [
                            PostingLine::debit(amount: (float) $entry->amount, accountId: $schedule->deferred_gl_account_id, narration: 'Release deferred income'),
                            PostingLine::credit(amount: (float) $entry->amount, accountId: $schedule->income_gl_account_id, narration: 'Recognised income'),
                        ],
                    );

                    $je = $this->posting->post($doc, $actor);

                    $entry->update([
                        'status'           => RevenueRecognitionEntry::STATUS_RECOGNIZED,
                        'recognized_at'    => now(),
                        'journal_entry_id' => $je->id,
                    ]);

                    $schedule->recognized_total = round((float) $schedule->recognized_total + (float) $entry->amount, 2);
                    if ($schedule->recognized_total >= round((float) $schedule->total_amount, 2) - 0.005) {
                        $schedule->status = RevenueRecognitionSchedule::STATUS_COMPLETED;
                        $report['completed']++;
                    }
                    $schedule->save();

                    $report['recognized']++;
                    $report['amount'] = round($report['amount'] + (float) $entry->amount, 2);
                });
            } catch (\Throwable $e) {
                // Fail-soft: a closed period or posting error leaves the entry
                // pending for a later run rather than aborting the whole batch.
                Log::warning('RevenueRecognitionService: failed to recognise entry; leaving pending.', [
                    'entry_id' => $entry->id, 'period' => $entry->period_month, 'error' => $e->getMessage(),
                ]);
                $report['errors']++;
            }
        }

        return $report;
    }
}
