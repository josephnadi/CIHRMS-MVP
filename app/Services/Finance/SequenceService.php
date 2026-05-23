<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

class SequenceService
{
    public function next(string $key): int
    {
        return DB::transaction(function () use ($key) {
            $row = DB::table('finance_sequences')
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                DB::table('finance_sequences')->insert([
                    'key'           => $key,
                    'current_value' => 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $current = 0;
            } else {
                $current = (int) $row->current_value;
            }

            $next = $current + 1;

            DB::table('finance_sequences')
                ->where('key', $key)
                ->update([
                    'current_value' => $next,
                    'updated_at'    => now(),
                ]);

            return $next;
        });
    }
}
