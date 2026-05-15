<?php

namespace App\Console\Commands;

use App\Integrations\OAuth\TokenRefresher;
use Illuminate\Console\Command;

class RefreshIntegrationTokens extends Command
{
    protected $signature = 'integrations:refresh-tokens {--minutes=5 : Refresh tokens expiring within N minutes}';

    protected $description = 'Refresh OAuth access tokens that are about to expire across all configured integrations.';

    public function handle(TokenRefresher $refresher): int
    {
        $minutes = (int) $this->option('minutes');
        $stats = $refresher->refreshExpiring($minutes);

        $this->info(sprintf(
            'Checked %d expiring tokens — refreshed %d, failed %d.',
            $stats['checked'], $stats['refreshed'], $stats['failed']
        ));

        return $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
