<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_sequences', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });

        $seeds = [
            ['key' => 'app_payment',      'table' => 'ap_payments',      'prefix' => 'APP-'],
            ['key' => 'ap_invoice',       'table' => 'vendor_invoices',  'prefix' => 'API-'],
            ['key' => 'ar_invoice',       'table' => 'ar_invoices',      'prefix' => 'ARI-'],
            ['key' => 'ar_receipt',       'table' => 'ar_receipts',      'prefix' => 'ARC-'],
            ['key' => 'payment_intent',   'table' => 'payment_intents',  'prefix' => 'PI-'],
            ['key' => 'journal',          'table' => 'journal_entries',  'prefix' => 'JE-'],
            ['key' => 'journal_reversal', 'table' => 'journal_entries',  'prefix' => 'JR-'],
        ];

        foreach ($seeds as $s) {
            if (!Schema::hasTable($s['table'])) {
                continue;
            }

            $rows = DB::table($s['table'])
                ->where('reference', 'like', $s['prefix'] . '%')
                ->pluck('reference');

            $byYear = [];
            foreach ($rows as $ref) {
                $parts = explode('-', (string) $ref);
                if (count($parts) < 3) {
                    continue;
                }
                $year = $parts[1];
                $num  = (int) $parts[2];
                $byYear[$year] = max($byYear[$year] ?? 0, $num);
            }

            foreach ($byYear as $year => $max) {
                DB::table('finance_sequences')->insert([
                    'key'           => "{$s['key']}:{$year}",
                    'current_value' => $max,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_sequences');
    }
};
