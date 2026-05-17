<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Accessibility\AccessibilityAuditor;
use Illuminate\Console\Command;

/**
 * php artisan a11y:audit
 *
 * Static-analysis sweep across resources/js (Vue) and resources/views (Blade)
 * for common WCAG 2.1 AA breakage — missing alt on <img>, icon-only buttons
 * without aria-label, inputs without an associated <label>, empty <a> tags,
 * and positive tabindex usage. Exits non-zero when any `error` severity
 * finding is present so the command is CI-friendly.
 *
 * The auditor is intentionally regex-light and high-precision: it errs on
 * the side of false negatives over false positives so CI signal stays clean.
 * Pair it with axe-core in browser E2E for full coverage.
 */
class AccessibilityAudit extends Command
{
    protected $signature = 'a11y:audit
                            {--json : Emit findings as JSON instead of a table}
                            {--severity=error : Lowest severity that fails the run (error|warning)}';

    protected $description = 'Audit Vue + Blade templates for WCAG 2.1 AA accessibility violations.';

    public function handle(AccessibilityAuditor $auditor): int
    {
        $findings = $auditor->audit(base_path());

        $threshold = $this->option('severity') === 'warning' ? 0 : 1;

        if ($this->option('json')) {
            $this->line(json_encode([
                'count'    => count($findings),
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            if (empty($findings)) {
                $this->info('No accessibility violations found.');
                return self::SUCCESS;
            }

            $this->table(
                ['Severity', 'WCAG', 'Rule', 'File:Line', 'Snippet'],
                array_map(fn ($f) => [
                    $f['severity'],
                    $f['wcag'],
                    $f['rule'],
                    $f['file'] . ':' . $f['line'],
                    mb_strimwidth($f['snippet'], 0, 60, '…'),
                ], $findings),
            );

            $errors   = array_filter($findings, fn ($f) => $f['severity'] === 'error');
            $warnings = array_filter($findings, fn ($f) => $f['severity'] === 'warning');
            $this->line(sprintf(
                '<options=bold>%d error(s), %d warning(s).</>',
                count($errors), count($warnings),
            ));
        }

        $shouldFail = collect($findings)->contains(
            fn ($f) => $threshold === 0 ? true : $f['severity'] === 'error',
        );

        return $shouldFail ? self::FAILURE : self::SUCCESS;
    }
}
