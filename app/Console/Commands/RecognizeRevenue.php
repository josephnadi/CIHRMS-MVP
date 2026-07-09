<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Finance\RevenueRecognitionService;
use Illuminate\Console\Command;

class RecognizeRevenue extends Command
{
    protected $signature = 'finance:recognize-revenue {month? : Period to recognise through, YYYY-MM (defaults to the current month)}';
    protected $description = 'Release deferred income (Subscription in Advance) to income for every entry due on or before the given month.';

    public function handle(RevenueRecognitionService $recognition): int
    {
        $month = (string) ($this->argument('month') ?? now()->format('Y-m'));

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error("Invalid month '{$month}'; expected YYYY-MM.");

            return self::FAILURE;
        }

        $r = $recognition->recognizeForMonth($month);
        $this->info(sprintf(
            'Revenue recognition through %s — recognized: %d, amount: %.2f, completed schedules: %d, errors: %d',
            $month, $r['recognized'], $r['amount'], $r['completed'], $r['errors'],
        ));

        return self::SUCCESS;
    }
}
