<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Tracks the most recent N password hashes per user and rejects re-use.
 * L6 audit fix. Default depth of 5 matches the most common compliance
 * minimum (PCI DSS / NIST SP 800-63B is silent but discouraging-of-reuse).
 */
class PasswordHistoryService
{
    public const HISTORY_DEPTH = 5;

    /**
     * Is the supplied plaintext password equal to one of the user's last
     * HISTORY_DEPTH passwords (or their current one)?
     */
    public function isRecent(User $user, string $plaintext): bool
    {
        if (Hash::check($plaintext, (string) $user->password)) {
            return true;
        }
        $hashes = DB::table('password_histories')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(self::HISTORY_DEPTH)
            ->pluck('password_hash');

        foreach ($hashes as $hash) {
            if (Hash::check($plaintext, (string) $hash)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record a NEW hash (already produced by Hash::make / the User cast)
     * and trim the user's history to HISTORY_DEPTH most-recent rows.
     */
    public function record(User $user, string $hash): void
    {
        DB::table('password_histories')->insert([
            'user_id'       => $user->id,
            'password_hash' => $hash,
            'created_at'    => now(),
        ]);

        // Trim. Keep the most recent HISTORY_DEPTH; delete the rest.
        $cutoffId = DB::table('password_histories')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->skip(self::HISTORY_DEPTH)
            ->take(1)
            ->value('id');

        if ($cutoffId !== null) {
            DB::table('password_histories')
                ->where('user_id', $user->id)
                ->where('id', '<=', $cutoffId)
                ->delete();
        }
    }
}
