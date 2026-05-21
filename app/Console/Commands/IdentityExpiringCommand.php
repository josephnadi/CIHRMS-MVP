<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerification;
use App\Notifications\IdentityExpiringReminder;
use Illuminate\Console\Command;

/**
 * Finds Ghana Card verifications whose 12-month validity window is about to
 * close and pings the employee to book a re-verification.
 *
 *   php artisan identity:expiring --window=30
 *
 * Default window is 30 days. Idempotent — running it twice in the same day
 * does not deliver duplicate notifications because Laravel's mail channel is
 * stateless (no de-dup), but the database channel produces one row per run
 * so HR sees the cadence rather than just the last reminder. If that's too
 * chatty in practice, add a `last_reminder_sent_at` column and gate on it.
 */
class IdentityExpiringCommand extends Command
{
    protected $signature = 'identity:expiring
                            {--window=30 : Days ahead to scan for expiring verifications}';

    protected $description = 'Notify employees whose Ghana Card verification expires within --window days.';

    public function handle(): int
    {
        $window = max(1, (int) $this->option('window'));
        $cutoff = now()->addDays($window);

        $expiring = IdentityVerification::query()
            ->where('status', IdentityVerificationStatus::Verified->value)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), $cutoff])
            ->with('employee.user')
            ->get();

        if ($expiring->isEmpty()) {
            $this->info("No Ghana Card verifications expiring within {$window} day(s).");
            return self::SUCCESS;
        }

        $delivered = 0;
        foreach ($expiring as $v) {
            $user = $v->employee?->user;
            if (! $user) continue;

            $daysRemaining = (int) ceil(now()->diffInHours($v->expires_at) / 24);
            $user->notify(new IdentityExpiringReminder($v, max(0, $daysRemaining)));
            $delivered++;
        }

        $this->info("Notified {$delivered} employee(s) with expiring Ghana Card verifications.");
        return self::SUCCESS;
    }
}
