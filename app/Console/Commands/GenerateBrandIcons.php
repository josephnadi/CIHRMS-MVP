<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generates branded PWA icons (PNG) using PHP-GD as a placeholder until the
 * design team hands over rasterised assets. The output matches the size/format
 * the `public/manifest.webmanifest` references so the PWA install banner has
 * something to show.
 *
 * Run:
 *   php artisan brand:icons             # default — write all five icon sizes
 *   php artisan brand:icons --force     # overwrite if files already exist
 *
 * Replace the generated PNGs with designer-provided assets when ready; no
 * code changes are needed — the manifest references files by path.
 */
class GenerateBrandIcons extends Command
{
    protected $signature = 'brand:icons {--force : Overwrite existing files}';

    protected $description = 'Generate placeholder PNG icons for PWA install + shortcuts.';

    // CIHRM brand palette — Sovereign Precision direction, May 2026.
    private const OBSIDIAN = [0x0A, 0x1F, 0x5C]; // #0A1F5C — primary surface
    private const COBALT   = [0x1D, 0x4E, 0xD8]; // #1D4ED8 — focus + accent
    private const PAPER    = [0xFF, 0xFF, 0xFF]; // #FFFFFF — monogram ink

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('PHP-GD extension required. Install php-gd and retry.');
            return self::FAILURE;
        }

        $base  = public_path('img/icons');
        if (! is_dir($base)) {
            mkdir($base, 0775, true);
        }

        $targets = [
            ['icon-192.png',          192, false],
            ['icon-512.png',          512, false],
            ['icon-maskable-192.png', 192, true],
            ['icon-maskable-512.png', 512, true],
            ['shortcut-clock.png',     96, false, 'clock'],
            ['shortcut-pay.png',       96, false, 'pay'],
            ['shortcut-leave.png',     96, false, 'leave'],
        ];

        foreach ($targets as $t) {
            $path     = "{$base}/{$t[0]}";
            $size     = $t[1];
            $maskable = $t[2];
            $glyph    = $t[3] ?? 'monogram';

            if (file_exists($path) && ! $this->option('force')) {
                $this->line("  skip {$t[0]} (exists; --force to overwrite)");
                continue;
            }

            $this->renderIcon($path, $size, $maskable, $glyph);
            $this->info("  wrote {$t[0]} ({$size}×{$size})");
        }

        return self::SUCCESS;
    }

    private function renderIcon(string $path, int $size, bool $maskable, string $glyph): void
    {
        $img = imagecreatetruecolor($size, $size);

        $obsidian = imagecolorallocate($img, ...self::OBSIDIAN);
        $cobalt   = imagecolorallocate($img, ...self::COBALT);
        $paper    = imagecolorallocate($img, ...self::PAPER);

        // Background — obsidian fill across the full square.
        imagefilledrectangle($img, 0, 0, $size, $size, $obsidian);

        // Maskable safe-area is the centre 80%. Draw the glyph inside that.
        $pad  = $maskable ? (int) ($size * 0.20 / 2) : (int) ($size * 0.12);
        $area = $size - 2 * $pad;

        match ($glyph) {
            'clock'  => $this->drawClock($img, $size, $pad, $area, $cobalt, $paper),
            'pay'    => $this->drawPay($img, $size, $pad, $area, $cobalt, $paper),
            'leave'  => $this->drawLeave($img, $size, $pad, $area, $cobalt, $paper),
            default  => $this->drawMonogram($img, $size, $pad, $area, $cobalt, $paper),
        };

        imagepng($img, $path, 6);
        imagedestroy($img);
    }

    /** Default app icon — a cobalt "C" inside the obsidian square. */
    private function drawMonogram($img, int $size, int $pad, int $area, int $cobalt, int $paper): void
    {
        // Cobalt accent ring (full-circle, slightly inset)
        $ringInset = (int) ($area * 0.08);
        imagefilledellipse(
            $img,
            (int) ($size / 2),
            (int) ($size / 2),
            $area - $ringInset,
            $area - $ringInset,
            $cobalt,
        );

        // Knockout obsidian inner circle so the cobalt becomes a ring
        $innerInset = (int) ($area * 0.22);
        imagefilledellipse(
            $img,
            (int) ($size / 2),
            (int) ($size / 2),
            $area - $innerInset,
            $area - $innerInset,
            imagecolorallocate($img, ...self::OBSIDIAN),
        );

        // "C" — paper-coloured arc carved out of the ring
        $arcW = (int) ($area * 0.62);
        $arcH = (int) ($area * 0.62);
        imagesetthickness($img, max(2, (int) ($size * 0.06)));
        imagearc(
            $img,
            (int) ($size / 2),
            (int) ($size / 2),
            $arcW,
            $arcH,
            -55,
            55,
            $paper,
        );
        // Cover the opening so it reads as a clean C (no wrap-around)
        // (handled by the arc's degree range above).
    }

    /** Clock-in shortcut — clock face with a single hand at 09:00 */
    private function drawClock($img, int $size, int $pad, int $area, int $cobalt, int $paper): void
    {
        $cx = (int) ($size / 2);
        $cy = (int) ($size / 2);
        $r  = (int) ($area * 0.42);

        imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $cobalt);
        imagefilledellipse($img, $cx, $cy, (int) ($r * 1.7), (int) ($r * 1.7), imagecolorallocate($img, ...self::OBSIDIAN));

        imagesetthickness($img, max(2, (int) ($size * 0.05)));
        // Hour hand (to 9 o'clock = left)
        imageline($img, $cx, $cy, $cx - (int) ($r * 0.55), $cy, $paper);
        // Minute hand (up to 12)
        imageline($img, $cx, $cy, $cx, $cy - (int) ($r * 0.75), $paper);
    }

    /** Pay shortcut — stylised "₵" stroke */
    private function drawPay($img, int $size, int $pad, int $area, int $cobalt, int $paper): void
    {
        $cx = (int) ($size / 2);
        $cy = (int) ($size / 2);
        $r  = (int) ($area * 0.40);

        imagesetthickness($img, max(2, (int) ($size * 0.07)));
        imagearc($img, $cx, $cy, $r * 2, $r * 2, 30, 330, $cobalt);
        // Vertical bar through the centre — the cedi mark
        imageline($img, $cx, $cy - $r, $cx, $cy + $r, $paper);
    }

    /** Leave shortcut — a stylised palm leaf */
    private function drawLeave($img, int $size, int $pad, int $area, int $cobalt, int $paper): void
    {
        $cx = (int) ($size / 2);
        $cy = (int) ($size / 2);

        // Body of the leaf — narrow ellipse rotated visually via filled-poly
        $w = (int) ($area * 0.50);
        $h = (int) ($area * 0.75);
        imagefilledellipse($img, $cx, $cy, $w, $h, $cobalt);

        // Central vein
        imagesetthickness($img, max(2, (int) ($size * 0.04)));
        imageline($img, $cx, $cy - (int) ($h / 2), $cx, $cy + (int) ($h / 2), $paper);
    }
}
