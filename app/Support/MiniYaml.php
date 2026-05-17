<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal, dependency-free YAML parser for the subset used by the OpenAPI v1
 * spec we ship from storage. Symfony/yaml is the standard tool, but it's
 * blocked on this project because PHP 8.5 conflicts with maatwebsite/excel's
 * phpoffice/phpspreadsheet pin. Rather than downgrade PHP or pin a stack we
 * don't control, we ship this one-file parser — narrow scope, well-tested
 * against `storage/api/openapi.v1.yaml`.
 *
 * Supported:
 *   - mappings (key: value)              - block & flow forms
 *   - sequences (- item)                 - block & flow forms
 *   - quoted strings ("..." or '...')
 *   - inline flow mappings { a: b, c: d }
 *   - inline flow sequences [a, b, c]
 *   - block-scalars `|`  (literal, newlines preserved)
 *   - block-scalars `>`  (folded, newlines→spaces)
 *   - comments (#) and blank lines
 *   - scalar types: int, float, bool, null, string
 *
 * NOT supported (intentionally — we don't use them in our spec):
 *   - anchors / aliases (& and *)
 *   - tags (!!)
 *   - merge keys (<<:)
 *   - directives (%YAML, %TAG)
 */
class MiniYaml
{
    public static function parseFile(string $path): array
    {
        $src = file_get_contents($path);
        if ($src === false) {
            throw new \RuntimeException("MiniYaml: unable to read {$path}");
        }
        return self::parse($src);
    }

    public static function parse(string $yaml): array
    {
        // Strip BOM and normalise line endings.
        $yaml = preg_replace('/^\xEF\xBB\xBF/', '', $yaml);
        $yaml = str_replace(["\r\n", "\r"], "\n", $yaml);
        $lines = explode("\n", $yaml);

        $i = 0;
        $result = self::parseBlock($lines, $i, 0);
        return is_array($result) ? $result : [];
    }

    /**
     * Parse a block of lines at the given indent level. Mutates $i to point
     * past the last consumed line.
     */
    private static function parseBlock(array $lines, int &$i, int $indent): mixed
    {
        $isList = null;
        $map    = [];
        $list   = [];

        while ($i < count($lines)) {
            $raw = $lines[$i];

            // Skip blank / comment lines.
            if (trim($raw) === '' || preg_match('/^\s*#/', $raw)) {
                $i++;
                continue;
            }

            $lineIndent = self::indentOf($raw);
            if ($lineIndent < $indent) break; // dedent — block ends.

            $line = substr($raw, $lineIndent);

            // List item
            if (str_starts_with($line, '- ') || $line === '-') {
                $isList = true;
                $afterDash = trim(substr($line, 1));
                $i++;

                if ($afterDash === '') {
                    // Nested structure on next lines.
                    $list[] = self::parseBlock($lines, $i, $indent + 2);
                    continue;
                }

                // `- key: value` style — a mapping starts on the same line as the dash.
                if (preg_match('/^([^:#]+):(\s|$)/', $afterDash)) {
                    $synthIndent = $indent + 2;
                    $synthetic   = str_repeat(' ', $synthIndent) . $afterDash;
                    array_splice($lines, $i, 0, [$synthetic]);
                    $list[] = self::parseBlock($lines, $i, $synthIndent);
                    continue;
                }

                $list[] = self::parseScalar($afterDash);
                continue;
            }

            // Mapping entry.
            if (preg_match('/^([^:#]+?):(\s*)(.*)$/', $line, $m)) {
                $isList = false;
                $key    = trim($m[1]);
                $rest   = $m[3];
                $i++;

                if (str_starts_with(trim($key), '"') || str_starts_with(trim($key), "'")) {
                    $key = trim(self::parseScalar($key));
                }

                // Empty RHS → nested block follows.
                if (trim($rest) === '' || str_starts_with(ltrim($rest), '#')) {
                    if ($i < count($lines) && trim($lines[$i]) !== '' && self::indentOf($lines[$i]) > $indent) {
                        $map[$key] = self::parseBlock($lines, $i, self::indentOf($lines[$i]));
                    } else {
                        $map[$key] = null;
                    }
                    continue;
                }

                // Block scalar literal | or folded >
                if ($rest === '|' || $rest === '>' || rtrim($rest) === '|' || rtrim($rest) === '>') {
                    $folded = (rtrim($rest) === '>');
                    $map[$key] = self::parseBlockScalar($lines, $i, $indent + 2, $folded);
                    continue;
                }

                $map[$key] = self::parseScalar(trim($rest));
                continue;
            }

            // Unknown line — skip.
            $i++;
        }

        if ($isList === true)  return $list;
        if ($isList === false) return $map;
        return [];
    }

    private static function parseBlockScalar(array $lines, int &$i, int $minIndent, bool $folded): string
    {
        $buf = [];
        while ($i < count($lines)) {
            $raw = $lines[$i];
            if (trim($raw) === '') {
                $buf[] = '';
                $i++;
                continue;
            }
            if (self::indentOf($raw) < $minIndent) break;
            $buf[] = substr($raw, $minIndent);
            $i++;
        }

        // Trim trailing blanks.
        while (count($buf) > 0 && end($buf) === '') array_pop($buf);

        if ($folded) {
            // Fold: blank line = paragraph break; consecutive non-blank lines join with space.
            $out = [];
            $para = [];
            foreach ($buf as $l) {
                if ($l === '') {
                    if ($para) $out[] = implode(' ', $para);
                    $out[] = '';
                    $para = [];
                } else {
                    $para[] = $l;
                }
            }
            if ($para) $out[] = implode(' ', $para);
            return implode("\n", $out);
        }

        return implode("\n", $buf);
    }

    private static function parseScalar(string $val): mixed
    {
        $val = trim($val);

        if ($val === '' || $val === '~' || strcasecmp($val, 'null') === 0) return null;
        if (strcasecmp($val, 'true')  === 0) return true;
        if (strcasecmp($val, 'false') === 0) return false;

        // Quoted string.
        if (strlen($val) >= 2) {
            if ($val[0] === '"'  && substr($val, -1) === '"')  return stripcslashes(substr($val, 1, -1));
            if ($val[0] === "'"  && substr($val, -1) === "'")  return str_replace("''", "'", substr($val, 1, -1));
        }

        // Flow sequence [a, b].
        if (str_starts_with($val, '[') && str_ends_with($val, ']')) {
            return self::parseFlowSequence(substr($val, 1, -1));
        }

        // Flow mapping { a: b }.
        if (str_starts_with($val, '{') && str_ends_with($val, '}')) {
            return self::parseFlowMapping(substr($val, 1, -1));
        }

        // Numbers.
        if (preg_match('/^-?\d+$/', $val)) return (int) $val;
        if (preg_match('/^-?\d*\.\d+(e[+-]?\d+)?$/i', $val)) return (float) $val;

        return $val;
    }

    private static function parseFlowSequence(string $body): array
    {
        $items = self::splitFlow($body);
        return array_map(fn ($x) => self::parseScalar(trim($x)), $items);
    }

    private static function parseFlowMapping(string $body): array
    {
        $items = self::splitFlow($body);
        $out = [];
        foreach ($items as $item) {
            if (! str_contains($item, ':')) continue;
            [$k, $v] = explode(':', $item, 2);
            $out[trim($k)] = self::parseScalar(trim($v));
        }
        return $out;
    }

    /** Split on top-level commas, respecting nesting and quotes. */
    private static function splitFlow(string $s): array
    {
        $out = [];
        $depth = 0;
        $buf = '';
        $inQ = null;
        for ($k = 0; $k < strlen($s); $k++) {
            $c = $s[$k];
            if ($inQ) {
                $buf .= $c;
                if ($c === $inQ) $inQ = null;
                continue;
            }
            if ($c === '"' || $c === "'") { $inQ = $c; $buf .= $c; continue; }
            if ($c === '[' || $c === '{') { $depth++; $buf .= $c; continue; }
            if ($c === ']' || $c === '}') { $depth--; $buf .= $c; continue; }
            if ($c === ',' && $depth === 0) { $out[] = $buf; $buf = ''; continue; }
            $buf .= $c;
        }
        if (trim($buf) !== '') $out[] = $buf;
        return $out;
    }

    private static function indentOf(string $line): int
    {
        $n = 0;
        while ($n < strlen($line) && $line[$n] === ' ') $n++;
        return $n;
    }
}
