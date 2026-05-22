<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use Illuminate\Database\Seeder;

class GlAccountBalanceSeeder extends Seeder
{
    /**
     * Ensures every gl_account has a corresponding balance row at zero.
     * Idempotent — safe to re-run after new accounts are added.
     */
    public function run(): void
    {
        GlAccount::query()->chunk(100, function ($accounts) {
            foreach ($accounts as $account) {
                GlAccountBalance::firstOrCreate(
                    ['gl_account_id' => $account->id],
                    ['balance' => 0]
                );
            }
        });
    }
}
