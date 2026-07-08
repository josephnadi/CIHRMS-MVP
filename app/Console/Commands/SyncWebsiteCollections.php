<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Website\WebsiteSyncService;
use Illuminate\Console\Command;

class SyncWebsiteCollections extends Command
{
    protected $signature = 'sync:website-collections';
    protected $description = 'Pull verified fee collections from cihrm_website and post them to the GL.';

    public function handle(WebsiteSyncService $sync): int
    {
        $r = $sync->sync();
        $this->info(sprintf(
            'Website sync complete — members: %d, pulled: %d, posted: %d, unmapped: %d, error: %d, flagged: %d, skipped: %d',
            $r['members'], $r['pulled'], $r['posted'], $r['unmapped'], $r['error'], $r['flagged'], $r['skipped'],
        ));

        return self::SUCCESS;
    }
}
