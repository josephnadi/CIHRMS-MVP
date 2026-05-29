<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\PayrollRunApproved;
use App\Events\PayrollRunCalculated;
use App\Events\PayrollRunPaid;
use App\Models\User;
use App\Notifications\PayrollRunApprovedNotification;
use App\Notifications\PayrollRunCalculatedNotification;
use App\Notifications\PayrollRunPaidNotification;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendPayrollNotifications implements ShouldQueue
{
    public function __construct(private readonly SmsDispatcher $sms) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof PayrollRunApproved   => $this->onApproved($event),
            $event instanceof PayrollRunCalculated => $this->onCalculated($event),
            $event instanceof PayrollRunPaid       => $this->onPaid($event),
            default                                => null,
        };
    }

    private function onApproved(PayrollRunApproved $event): void
    {
        $audience = $this->holders('payroll.manage')->concat($this->holders('finance.hub'))->unique('id');
        $notification = new PayrollRunApprovedNotification($event->run);
        foreach ($audience as $user) {
            $user->notify($notification);
        }
    }

    private function onCalculated(PayrollRunCalculated $event): void
    {
        $creator = User::find($event->run->created_by);
        if (! $creator) {
            return;
        }
        $creator->notify(new PayrollRunCalculatedNotification($event->run));
    }

    private function onPaid(PayrollRunPaid $event): void
    {
        $event->run->lines()->with(['employee.user', 'run'])->get()->each(function ($line) {
            $user = $line->employee?->user;
            if (! $user) {
                return;
            }
            $notification = new PayrollRunPaidNotification($line);
            $user->notify($notification);
            $phone = $line->employee?->phone;
            if ($phone) {
                $this->sms->send(
                    toPhone:     $phone,
                    body:        $notification->toSmsBody($user),
                    contextType: 'payroll',
                    contextId:   $line->payroll_run_id,
                );
            }
        });
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
