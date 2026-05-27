<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use App\Services\Auth\PasswordHistoryService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a new password that matches one of the user's last
 * PasswordHistoryService::HISTORY_DEPTH passwords. L6 audit fix.
 */
class NotRecentPassword implements ValidationRule
{
    public function __construct(private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->user || ! is_string($value)) return;

        if (app(PasswordHistoryService::class)->isRecent($this->user, $value)) {
            $fail("This :attribute matches a recent one. Pick something you haven't used in the last 5 changes.");
        }
    }
}
