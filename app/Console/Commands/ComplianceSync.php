<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Learning\ComplianceAssignmentService;
use Illuminate\Console\Command;

class ComplianceSync extends Command
{
    protected $signature = 'compliance:sync';

    protected $description = 'Assign mandatory compliance courses to all matching employees.';

    public function handle(ComplianceAssignmentService $compliance): int
    {
        $n = $compliance->syncAll();
        $this->info("Compliance sync complete: {$n} assignment(s) made.");

        return self::SUCCESS;
    }
}
