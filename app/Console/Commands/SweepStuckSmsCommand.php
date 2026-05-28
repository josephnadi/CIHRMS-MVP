<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SmsStatus;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\SmsMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Belt-and-braces sweep for SmsMessage rows stuck in Queued state past
 * `--stuck-after`. Indicates a worker crashed or the queue itself paused
 * between row insert and job pickup. Re-dispatches SendSmsJob, which is
 * idempotent (status check inside handle()).
 */
class SweepStuckSmsCommand extends Command
{
    protected $signature = 'messaging:sweep-stuck-sms
                            {--stuck-after=10 : Minutes a row must be Queued before sweeping}';

    protected $description = 'Re-dispatch SendSmsJob for SmsMessage rows stuck in Queued state.';

    public function handle(): int
    {
        $minutes = (int) $this->option('stuck-after');
        $cutoff = now()->subMinutes($minutes);

        $count = 0;
        SmsMessage::where('status', SmsStatus::Queued->value)
            ->where('created_at', '<', $cutoff)
            ->chunkById(500, function ($chunk) use (&$count) {
                foreach ($chunk as $message) {
                    SendSmsJob::dispatch($message->id);
                    $count++;
                }
            });

        $noun = $count === 1 ? 'row' : 'rows';
        $this->info("Re-dispatched {$count} stuck SMS {$noun} (Queued > {$minutes} min).");
        Log::info('messaging:sweep-stuck-sms', ['count' => $count, 'minutes' => $minutes]);

        return self::SUCCESS;
    }
}
