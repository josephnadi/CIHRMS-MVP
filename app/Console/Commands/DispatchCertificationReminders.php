<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GovernanceService;
use Illuminate\Console\Command;

class DispatchCertificationReminders extends Command
{
    protected $signature = 'governance:certification-reminders {--days=30 : Days-ahead window for upcoming expiries}';
    protected $description = 'Dispatch CertificationExpiring events for any certification expiring within --days days without a prior reminder.';

    public function handle(GovernanceService $service): int
    {
        $days = (int) ($this->option('days') ?: 30);
        $count = $service->dispatchExpiryReminders($days);

        $this->info("Dispatched {$count} certification reminder events (window: {$days}d).");
        return self::SUCCESS;
    }
}
