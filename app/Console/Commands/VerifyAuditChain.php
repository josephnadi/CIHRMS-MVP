<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AuditChainBroken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Re-hashes every audit_logs row in chain_position order and compares each
 * computed digest to the persisted `row_hash` (and `previous_hash`).
 *
 * Exits 0 on success, non-zero on first mismatch — suitable for cron alerts.
 * With `--notify`, also dispatches `AuditChainBroken` to every `super_admin`
 * so a real human sees the incident even if no one is watching the exit code.
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify-chain
                            {--limit=0   : Stop after N rows (0 = no limit)}
                            {--from=0    : Resume from this chain_position}
                            {--notify    : Notify super_admins on failure}';

    protected $description = 'Verify the tamper-evident audit log hash chain.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $from  = (int) $this->option('from');

        $query = AuditLog::query()
            ->orderBy('chain_position')
            ->when($from > 0, fn ($q) => $q->where('chain_position', '>=', $from));

        $expectedPrevious = $from > 0
            ? AuditLog::where('chain_position', $from - 1)->value('row_hash')
            : null;

        $expectedPosition = $from > 0 ? $from : 1;
        $checked = 0;
        $broken  = null;

        $iterator = $query->cursor();

        foreach ($iterator as $row) {
            if ($row->chain_position !== $expectedPosition) {
                $broken = ['row' => $row, 'reason' => "expected chain_position {$expectedPosition}, got {$row->chain_position}"];
                break;
            }
            if ($row->previous_hash !== $expectedPrevious) {
                $broken = ['row' => $row, 'reason' => 'previous_hash mismatch'];
                break;
            }

            $computed = $row->computeHash();
            if (! hash_equals((string) $row->row_hash, $computed)) {
                $broken = ['row' => $row, 'reason' => 'row_hash mismatch (data tampered)'];
                break;
            }

            $expectedPrevious = $row->row_hash;
            $expectedPosition++;
            $checked++;

            if ($limit > 0 && $checked >= $limit) break;
        }

        if ($broken !== null) {
            /** @var AuditLog $row */
            $row = $broken['row'];
            $this->error(sprintf(
                "Audit chain BROKEN at position %d (audit_logs.id=%d): %s",
                $row->chain_position,
                $row->id,
                $broken['reason'],
            ));

            if ($this->option('notify')) {
                $recipients = User::query()->where('role', 'super_admin')->get();
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, AuditChainBroken::from($row, $broken['reason'], $checked));
                    $this->warn(sprintf('Notified %d super_admin(s).', $recipients->count()));
                }
            }

            return self::FAILURE;
        }

        $this->info("Audit chain verified: {$checked} row(s) intact.");
        return self::SUCCESS;
    }
}
