<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\LoanApproved;
use App\Events\LoanDisbursed;
use App\Events\LoanFullyRepaid;
use App\Models\User;
use App\Notifications\LoanApprovedNotification;
use App\Notifications\LoanDisbursedNotification;
use App\Notifications\LoanFullyRepaidNotification;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendLoanNotifications implements ShouldQueue
{
    public function __construct(private readonly SmsDispatcher $sms) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof LoanApproved    => $this->onApproved($event),
            $event instanceof LoanDisbursed   => $this->onDisbursed($event),
            $event instanceof LoanFullyRepaid => $this->onRepaid($event),
            default                           => null,
        };
    }

    private function onApproved(LoanApproved $event): void
    {
        $loan = $event->loan->loadMissing([
            'employee.user',
            'employee.manager.user',
        ]);

        $applicant = $loan->employee?->user;
        $manager   = $loan->employee?->manager?->user;
        $notification = new LoanApprovedNotification($loan);

        foreach (array_filter([$applicant, $manager]) as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function onDisbursed(LoanDisbursed $event): void
    {
        $loan = $event->loan->loadMissing([
            'employee.user.employee',
        ]);

        $applicant  = $loan->employee?->user;
        $disbursers = $this->holders('loans.disburse')->each->loadMissing('employee');
        $notification = new LoanDisbursedNotification($loan);

        $recipients = collect(array_filter([$applicant]))->concat($disbursers)->unique('id');
        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
            $this->sendSmsIfPhoned($recipient, $notification, 'loan', $loan->id);
        }
    }

    private function onRepaid(LoanFullyRepaid $event): void
    {
        $loan = $event->loan->loadMissing([
            'employee.user.employee',
        ]);

        $applicant = $loan->employee?->user;
        if (! $applicant) {
            return;
        }
        $notification = new LoanFullyRepaidNotification($loan);
        $applicant->notify($notification);
        $this->sendSmsIfPhoned($applicant, $notification, 'loan', $loan->id);
    }

    private function sendSmsIfPhoned(User $recipient, object $notification, string $contextType, int $contextId): void
    {
        // Phone lives on the Employee row, not the User row.
        $phone = $recipient->employee?->phone;
        if (! $phone) {
            return;
        }
        $this->sms->send(
            toPhone:     $phone,
            body:        $notification->toSmsBody($recipient),
            contextType: $contextType,
            contextId:   $contextId,
        );
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
