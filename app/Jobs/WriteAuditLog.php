<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Tamper-evident audit log writer.
 *
 * Each row links to the previous row via SHA-256, forming a hash chain.
 * Any post-hoc mutation of an `audit_logs` row is detectable via
 * `php artisan audit:verify-chain`, which re-hashes the chain end-to-end.
 *
 * The lock-for-update on the latest row + the transaction guarantee a
 * total order even under concurrent inserts.
 */
class WriteAuditLog implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly array $data)
    {
        $this->onQueue('audit');
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $latest = AuditLog::query()
                ->orderByDesc('chain_position')
                ->lockForUpdate()
                ->first();

            $position = ($latest?->chain_position ?? 0) + 1;
            $previousHash = $latest?->row_hash; // null for the genesis row

            $row = AuditLog::create([
                ...$this->data,
                'chain_position' => $position,
                'previous_hash'  => $previousHash,
                'row_hash'       => null, // computed below once id is known
            ]);

            // Compute final hash AFTER id + created_at are settled.
            $row->row_hash = $row->computeHash();
            $row->saveQuietly();
        });
    }
}
