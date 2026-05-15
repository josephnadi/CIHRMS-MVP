<?php

namespace App\Listeners;

use App\Events\PayrollRunApproved;
use App\Services\Payroll\StatutoryReturnGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class GenerateStatutoryReturns implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function viaQueue(): string
    {
        return 'payroll';
    }

    public function __construct(private readonly StatutoryReturnGenerator $generator) {}

    public function handle(PayrollRunApproved $event): void
    {
        $this->generator->generateAll($event->run);
    }
}
