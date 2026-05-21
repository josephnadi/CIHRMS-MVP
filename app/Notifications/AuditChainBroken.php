<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Critical audit-trail incident — the SHA-256 hash chain over `audit_logs`
 * does not validate end-to-end. Fired by the scheduled `audit:verify-chain`
 * cron when a row's `row_hash` no longer matches its content, indicating
 * either direct database tampering or accidental data corruption.
 *
 * Routed via the user's mail + database channels. Slack/SMS deliberately
 * not used: a broken chain is high-severity but not user-facing, and the
 * audit trail itself is the primary record of who saw the alert and when.
 */
class AuditChainBroken extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $brokenPosition,
        public readonly ?int $brokenAuditLogId,
        public readonly string $reason,
        public readonly int $checked,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('CIHRMS audit chain BROKEN — investigation required')
            ->error()
            ->line('The tamper-evident audit log hash chain failed end-to-end verification.')
            ->line("Broken at chain_position **{$this->brokenPosition}** (audit_logs.id={$this->brokenAuditLogId}).")
            ->line("Reason: {$this->reason}")
            ->line("Rows verified before break: {$this->checked}")
            ->line('Treat this as a potential security incident. Quarantine the affected row, capture forensic evidence, and run `php artisan audit:verify-chain --from=' . max(1, $this->brokenPosition - 5) . '` to inspect surrounding rows.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'            => 'audit_chain_broken',
            'broken_position' => $this->brokenPosition,
            'audit_log_id'    => $this->brokenAuditLogId,
            'reason'          => $this->reason,
            'checked'         => $this->checked,
        ];
    }

    /**
     * Convenience for the verify command — returns a payload it can hand to
     * `Notification::send($users, AuditChainBroken::from($row, $reason, $checked))`.
     */
    public static function from(?AuditLog $row, string $reason, int $checked): self
    {
        return new self(
            brokenPosition: (int) ($row?->chain_position ?? 0),
            brokenAuditLogId: $row?->id,
            reason: $reason,
            checked: $checked,
        );
    }
}
