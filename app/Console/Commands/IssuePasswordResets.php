<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/**
 * Issue password resets for users who would otherwise be locked out by the
 * login-password requirement introduced in PR #34.
 *
 * Targets:
 *   • Users with a NULL / empty password hash (no way to log in at all).
 *   • Optionally users with the dev-default password "password" (--include-default-password).
 *
 * Per affected user:
 *   • Sets password_must_change = true (the password-change wall fires on first login).
 *   • Generates a Laravel password-reset token + URL.
 *   • Either prints (operator distributes manually) or emails (--email).
 *
 * Examples:
 *   php artisan users:issue-password-resets --dry-run
 *   php artisan users:issue-password-resets                              # print URLs
 *   php artisan users:issue-password-resets --email                      # send emails
 *   php artisan users:issue-password-resets --include-default-password   # also seed-default users
 */
class IssuePasswordResets extends Command
{
    protected $signature = 'users:issue-password-resets
                            {--dry-run                : Report what would change without writing}
                            {--email                  : Send Laravel password-reset emails (requires MAIL_*)}
                            {--include-default-password : Also target users whose password is the dev default "password"}
                            {--exclude= : Comma-separated staff IDs to skip}';

    protected $description = 'Issue password resets for users who would be locked out by the password requirement.';

    public function handle(): int
    {
        $dryRun         = (bool) $this->option('dry-run');
        $emailMode      = (bool) $this->option('email');
        $includeDefault = (bool) $this->option('include-default-password');
        $exclude        = array_filter(array_map('trim', explode(',', (string) $this->option('exclude'))));

        $this->info(sprintf(
            'Mode: %s | %s | %s%s',
            $dryRun ? 'DRY RUN' : 'WRITE',
            $emailMode ? 'email' : 'print URLs',
            $includeDefault ? 'NULL + dev-default' : 'NULL only',
            $exclude ? ' | excluding ' . count($exclude) . ' staff ID(s)' : '',
        ));

        $candidates = User::query()
            ->when($exclude, fn ($q) => $q->whereNotIn('staff_id', $exclude))
            ->get()
            ->filter(function (User $u) use ($includeDefault) {
                $pw = (string) ($u->password ?? '');
                if ($pw === '') return true;
                if ($includeDefault && Hash::check('password', $pw)) return true;
                return false;
            })
            ->values();

        if ($candidates->isEmpty()) {
            $this->info('No users need a reset. Nothing to do.');
            return self::SUCCESS;
        }

        $this->info("Affected users: {$candidates->count()}");

        $rows = [];
        $sent = 0;
        $printed = 0;

        foreach ($candidates as $user) {
            $reason = ((string) ($user->password ?? '')) === '' ? 'no-password' : 'dev-default';

            if ($dryRun) {
                $rows[] = [
                    $user->staff_id,
                    $user->name,
                    $user->email,
                    $reason,
                    '(dry-run — no token generated)',
                ];
                continue;
            }

            $user->forceFill(['password_must_change' => true])->save();

            if ($emailMode) {
                $status = Password::sendResetLink(['email' => $user->email]);
                $delivered = $status === Password::RESET_LINK_SENT;
                if ($delivered) $sent++;
                $rows[] = [
                    $user->staff_id,
                    $user->name,
                    $user->email,
                    $reason,
                    $delivered ? 'email sent' : "email failed: {$status}",
                ];
            } else {
                $token = Password::getRepository()->create($user);
                $url = URL::route('password.reset', [
                    'token' => $token,
                    'email' => $user->email,
                ]);
                $printed++;
                $rows[] = [
                    $user->staff_id,
                    $user->name,
                    $user->email,
                    $reason,
                    $url,
                ];
            }
        }

        $this->table(['Staff ID', 'Name', 'Email', 'Reason', $emailMode ? 'Delivery' : 'Reset URL'], $rows);

        if ($dryRun) {
            $this->warn('Dry-run only — re-run without --dry-run to apply.');
            return self::SUCCESS;
        }

        if ($emailMode) {
            $this->info("Emails sent: {$sent} / {$candidates->count()}");
            if ($sent < $candidates->count()) {
                $this->warn('Some emails failed. Review the table above; failures typically mean throttling or invalid mailer config.');
            }
        } else {
            $this->info("Reset URLs printed for {$printed} user(s). Distribute via your secure channel.");
            $this->warn('Reset URLs contain single-use tokens. Do NOT paste them into a public channel.');
        }

        return self::SUCCESS;
    }
}
