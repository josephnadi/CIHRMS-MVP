<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\BenefitClaimDecided;
use App\Events\BenefitClaimSubmitted;
use App\Models\User;
use App\Notifications\BenefitClaimDecidedNotification;
use App\Notifications\BenefitClaimSubmittedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendBenefitsNotifications implements ShouldQueue
{
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof BenefitClaimSubmitted => $this->onSubmitted($event),
            $event instanceof BenefitClaimDecided   => $this->onDecided($event),
            default                                 => null,
        };
    }

    private function onSubmitted(BenefitClaimSubmitted $event): void
    {
        $notification = new BenefitClaimSubmittedNotification($event->claim);
        foreach ($this->holders('benefits.manage') as $reviewer) {
            $reviewer->notify($notification);
        }
    }

    private function onDecided(BenefitClaimDecided $event): void
    {
        $claimant = $event->claim->enrolment?->employee?->user;
        if (! $claimant) return;
        $claimant->notify(new BenefitClaimDecidedNotification($event->claim));
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
