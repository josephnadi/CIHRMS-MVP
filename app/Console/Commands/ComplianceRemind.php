<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Enrolment;
use App\Notifications\ComplianceTrainingDue;
use Illuminate\Console\Command;

class ComplianceRemind extends Command
{
    protected $signature = 'compliance:remind';

    protected $description = 'Notify employees with overdue mandatory (compliance) training.';

    public function handle(): int
    {
        $count = 0;

        Enrolment::overdue()
            ->with(['employee.user', 'course'])
            ->get()
            ->each(function (Enrolment $enrolment) use (&$count) {
                $user = $enrolment->employee?->user;

                if ($user !== null) {
                    $user->notify(new ComplianceTrainingDue($enrolment));
                    $count++;
                }
            });

        $this->info("Compliance reminders sent: {$count}.");

        return self::SUCCESS;
    }
}
