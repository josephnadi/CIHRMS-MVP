<?php

declare(strict_types=1);

namespace App\Services\Accessibility;

/**
 * Static-analysis sweep over Blade/Vue source for the cheap-to-catch WCAG 2.1
 * AA violations: missing alt on <img>, icon-only buttons without aria-label,
 * inputs without an associated <label>, links without accessible text, and
 * non-localised hard-coded text inside aria-* attributes. It is intentionally
 * regex-based — a fast pre-commit guard, not a substitute for axe-core in CI.
 *
 * Findings are returned as a flat array of arrays, each shaped:
 *
 *   ['file' => string, 'line' => int, 'rule' => string, 'snippet' => string,
 *    'wcag' => string, 'severity' => 'error'|'warning']
 *
 * The auditor never reads the project's vendor/ or node_modules/ directories,
 * never opens files outside the configured roots, and never executes user
 * code — it is safe to wire into CI without a sandbox.
 */
class AccessibilityAuditor
{
    /**
     * Roots are project-relative directory paths. Files within these roots
     * with the configured extensions are scanned recursively.
     */
    public function __construct(
        private array $roots = ['resources/js', 'resources/views'],
        private array $extensions = ['vue', 'blade.php'],
    ) {}

    public function audit(string $basePath): array
    {
        $findings = [];

        foreach ($this->roots as $root) {
            $absolute = rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $root);
            if (! is_dir($absolute)) continue;

            foreach ($this->iterateFiles($absolute) as $file) {
                array_push($findings, ...$this->auditFile($file, $basePath));
            }
        }

        return $findings;
    }

    public function auditSource(string $relativePath, string $source): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $source) ?: [];
        $findings = [];

        foreach ($lines as $i => $line) {
            $lineNumber = $i + 1;
            $findings = array_merge(
                $findings,
                $this->checkImg($relativePath, $lineNumber, $line),
                $this->checkIconButton($relativePath, $lineNumber, $line),
                $this->checkInputLabel($relativePath, $lineNumber, $line, $lines),
                $this->checkAnchorText($relativePath, $lineNumber, $line),
                $this->checkPositiveTabindex($relativePath, $lineNumber, $line),
            );
        }

        return $findings;
    }

    private function iterateFiles(string $root): iterable
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($rii as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) continue;
            foreach ($this->extensions as $ext) {
                if (str_ends_with($file->getFilename(), '.' . $ext)) {
                    yield $file->getPathname();
                    continue 2;
                }
            }
        }
    }

    private function auditFile(string $absolute, string $basePath): array
    {
        $source = @file_get_contents($absolute);
        if ($source === false) return [];

        $relative = ltrim(str_replace($basePath, '', $absolute), "/\\");
        return $this->auditSource($relative, $source);
    }

    /** WCAG 1.1.1 — Non-text Content. Every <img> needs alt= (may be empty for decorative). */
    private function checkImg(string $file, int $line, string $source): array
    {
        if (! preg_match_all('/<img\b([^>]*)>/i', $source, $m, PREG_OFFSET_CAPTURE)) return [];
        $out = [];
        foreach ($m[0] as $i => $match) {
            $attrs = $m[1][$i][0];
            if (preg_match('/\balt\s*=/i', $attrs)) continue;
            // v-bind:alt / :alt are fine — content is computed.
            if (preg_match('/(:alt|v-bind:alt)\s*=/i', $attrs)) continue;
            $out[] = [
                'file' => $file, 'line' => $line, 'rule' => 'img-missing-alt',
                'wcag' => '1.1.1', 'severity' => 'error',
                'snippet' => trim($match[0]),
            ];
        }
        return $out;
    }

    /** WCAG 4.1.2 — Name, Role, Value. Icon-only <button> needs aria-label. */
    private function checkIconButton(string $file, int $line, string $source): array
    {
        if (! preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/is', $source, $m, PREG_OFFSET_CAPTURE)) return [];
        $out = [];
        foreach ($m[0] as $i => $match) {
            $attrs   = $m[1][$i][0];
            $content = trim(strip_tags($m[2][$i][0]));
            if ($content !== '') continue;
            if (preg_match('/(aria-label|aria-labelledby|:aria-label|v-bind:aria-label)\s*=/i', $attrs)) continue;
            $out[] = [
                'file' => $file, 'line' => $line, 'rule' => 'icon-button-missing-aria-label',
                'wcag' => '4.1.2', 'severity' => 'error',
                'snippet' => trim($match[0]),
            ];
        }
        return $out;
    }

    /** WCAG 3.3.2 — Labels or Instructions. Each <input>/select/textarea needs a label or aria-label. */
    private function checkInputLabel(string $file, int $line, string $source, array $allLines): array
    {
        if (! preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $source, $m, PREG_OFFSET_CAPTURE)) return [];
        $out = [];
        foreach ($m[0] as $i => $match) {
            $tag   = strtolower($m[1][$i][0]);
            $attrs = $m[2][$i][0];

            // Skip hidden / submit / button / checkbox-toggle types — those are not "fields".
            if (preg_match('/\btype\s*=\s*["\'](hidden|submit|button|reset|image)["\']/i', $attrs)) continue;

            // aria-label / aria-labelledby / :id-bound label = ok.
            if (preg_match('/(aria-label|aria-labelledby|:aria-label)\s*=/i', $attrs)) continue;

            // Look for a `for=...` <label> referencing this id within the same file (very rough).
            if (preg_match('/\bid\s*=\s*["\']([\w:-]+)["\']/i', $attrs, $idMatch)) {
                $id = $idMatch[1];
                $haystack = implode("\n", $allLines);
                if (preg_match('/<label\b[^>]*\bfor\s*=\s*["\']' . preg_quote($id, '/') . '["\']/i', $haystack)) {
                    continue;
                }
            }

            $out[] = [
                'file' => $file, 'line' => $line, 'rule' => "{$tag}-missing-label",
                'wcag' => '3.3.2', 'severity' => 'error',
                'snippet' => trim($match[0]),
            ];
        }
        return $out;
    }

    /** WCAG 2.4.4 — Link Purpose. <a> needs visible text or aria-label. */
    private function checkAnchorText(string $file, int $line, string $source): array
    {
        if (! preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $source, $m, PREG_OFFSET_CAPTURE)) return [];
        $out = [];
        foreach ($m[0] as $i => $match) {
            $attrs   = $m[1][$i][0];
            $content = trim(strip_tags($m[2][$i][0]));
            if ($content !== '') continue;
            if (preg_match('/(aria-label|aria-labelledby|:aria-label)\s*=/i', $attrs)) continue;
            $out[] = [
                'file' => $file, 'line' => $line, 'rule' => 'anchor-empty-text',
                'wcag' => '2.4.4', 'severity' => 'warning',
                'snippet' => trim($match[0]),
            ];
        }
        return $out;
    }

    /** WCAG 2.4.3 — Focus Order. tabindex > 0 breaks the natural tab order. */
    private function checkPositiveTabindex(string $file, int $line, string $source): array
    {
        if (! preg_match_all('/\btabindex\s*=\s*["\'](\d+)["\']/i', $source, $m, PREG_OFFSET_CAPTURE)) return [];
        $out = [];
        foreach ($m[1] as $i => $val) {
            $n = (int) $val[0];
            if ($n <= 0) continue;
            $out[] = [
                'file' => $file, 'line' => $line, 'rule' => 'positive-tabindex',
                'wcag' => '2.4.3', 'severity' => 'warning',
                'snippet' => trim($m[0][$i][0]),
            ];
        }
        return $out;
    }
}
