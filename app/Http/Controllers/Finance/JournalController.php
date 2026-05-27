<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreManualJournalEntryRequest;
use App\Http\Resources\Finance\JournalEntryResource;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Finance\JournalPostingService;
use App\Services\Finance\SequenceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    public function __construct(
        private readonly JournalPostingService $service,
        private readonly SequenceService $sequences,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'source_type', 'from', 'to']);

        $q = JournalEntry::query()->with(['creator:id,name', 'poster:id,name']);
        if (! empty($filters['status']))      $q->where('status', $filters['status']);
        if (! empty($filters['source_type'])) $q->where('source_type', $filters['source_type']);
        if (! empty($filters['from']))        $q->whereDate('entry_date', '>=', $filters['from']);
        if (! empty($filters['to']))          $q->whereDate('entry_date', '<=', $filters['to']);

        $entries = $q->orderByDesc('entry_date')->orderByDesc('id')->paginate(50)->withQueryString();

        return Inertia::render('Finance/Journal/Index', [
            'activeModule' => 'finance-journal',
            'entries'      => JournalEntryResource::collection($entries),
            'filters'      => $filters,
        ]);
    }

    public function show(JournalEntry $journalEntry, Request $request): Response
    {
        // Defense-in-depth (M8): mirror the route-group permission gate.
        abort_unless($request->user()?->hasPermission('journal.view'), 403);

        $journalEntry->load(['lines.glAccount', 'creator:id,name', 'poster:id,name']);

        return Inertia::render('Finance/Journal/Index', [
            'activeModule' => 'finance-journal',
            'focusEntry'   => (new JournalEntryResource($journalEntry))->resolve(),
        ]);
    }

    public function store(StoreManualJournalEntryRequest $request): RedirectResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();

            $je = JournalEntry::create([
                'reference'   => $this->nextManualRef(),
                'entry_date'  => $data['entry_date'],
                'narration'   => $data['narration'] ?? null,
                'status'      => JournalEntryStatus::Draft->value,
                'source_type' => JournalSourceType::Manual->value,
                'created_by'  => $request->user()->id,
            ]);

            $lineNo = 1;
            foreach ($data['lines'] as $line) {
                JournalLine::create([
                    'journal_entry_id' => $je->id,
                    'line_no'          => $lineNo++,
                    'gl_account_id'    => $line['gl_account_id'],
                    'debit_amount'     => (float) $line['debit_amount'],
                    'credit_amount'    => (float) $line['credit_amount'],
                    'narration'        => $line['narration'] ?? null,
                ]);
            }

            try {
                $this->service->post($je->fresh('lines.glAccount'));
            } catch (DomainException $e) {
                throw $e;
            }

            return back()->with('success', "Manual journal {$je->reference} posted.");
        });
    }

    private function nextManualRef(): string
    {
        // Uses SequenceService (FOR UPDATE row lock in finance_sequences)
        // so two concurrent manual-JE submissions cannot collide on the
        // same JM-YYYY-NNNNNN reference. Closes the race-condition gap
        // documented in the 2026-05-26 audit (H11); count()+1 is unsafe
        // under contention.
        $year = now()->format('Y');
        $n    = $this->sequences->next("journal_manual:{$year}");
        return sprintf('JM-%s-%06d', $year, $n);
    }
}
