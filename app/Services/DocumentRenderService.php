<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;

class DocumentRenderService
{
    private const DISK = 'local';

    /**
     * Render a version with all annotations burned in. Returns absolute path
     * to the PDF.
     *
     * When `$watermark` is null, the result is cached per
     * (version_id, annotation_set_hash). When `$watermark` is non-null, the
     * cache is bypassed because the watermark text is per-viewer/per-timestamp
     * and uniqueness is the whole point — caching would leak one viewer's
     * watermarked file to another viewer.
     *
     * Watermark shape: `['text' => string, 'tone' => 'restricted'|'confidential'|...]`.
     */
    public function burn(DocumentVersion $version, ?array $watermark = null): string
    {
        $doc = $version->document;
        $annotations = $doc->annotations()
            ->where('version_id', $version->id)
            ->orderBy('page')
            ->get();

        $useCache = $watermark === null && $doc->watermark_id === null;

        if ($useCache) {
            $hash = $this->annotationHash($annotations);
            $cachePath = sprintf('documents/%s/v%d/burned-%s.pdf', $doc->uuid, $version->version_no, $hash);

            if (Storage::disk(self::DISK)->exists($cachePath)) {
                return Storage::disk(self::DISK)->path($cachePath);
            }
        }

        $sourcePath = Storage::disk(self::DISK)->path($version->storage_path);

        // Image source → wrap into a single-page PDF first.
        if (str_starts_with($version->mime, 'image/')) {
            $sourcePath = $this->imageToPdf($sourcePath);
        } elseif ($version->mime !== 'application/pdf') {
            // DOCX et al. — burn-in unsupported in v1; return the original.
            return Storage::disk(self::DISK)->path($version->storage_path);
        }

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($sourcePath);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $tplId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($tplId);
            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId);

            $pageWidth  = $size['width'];
            $pageHeight = $size['height'];

            foreach ($annotations->where('page', $pageNo) as $a) {
                $this->drawAnnotation($pdf, $a, $pageWidth, $pageHeight);
            }

            // Per-document watermark template overrides / augments the auto restricted watermark.
            $tpl = $doc->watermark; // null when documents.watermark_id IS NULL
            if ($tpl) {
                $this->drawTemplateWatermark($pdf, $tpl, $pageWidth, $pageHeight);
            } elseif ($watermark) {
                $this->drawWatermark($pdf, $watermark, $pageWidth, $pageHeight);
            }
        }

        if ($useCache) {
            $absolutePath = Storage::disk(self::DISK)->path($cachePath);
            @mkdir(dirname($absolutePath), 0775, true);
        } else {
            // Per-viewer watermarked file — write to a temp path; the
            // controller streams it as a download and the OS reaps the file.
            $absolutePath = tempnam(sys_get_temp_dir(), 'wm') . '.pdf';
        }
        $pdf->Output($absolutePath, 'F');

        return $absolutePath;
    }

    /**
     * Diagonal, semi-transparent watermark drawn across the page after the
     * annotations have been overlaid. Pairs with the F-1 restricted-download
     * policy: the viewer's name + timestamp + classification are rendered on
     * every page so leaked screenshots/exports can be traced back.
     */
    private function drawWatermark(Fpdi $pdf, array $watermark, float $pageW, float $pageH): void
    {
        $text = (string) ($watermark['text'] ?? 'CONFIDENTIAL');
        $tone = (string) ($watermark['tone'] ?? 'restricted');

        // Tone → RGB. Restricted is rose-tinted; everything else is slate.
        [$r, $g, $b] = $tone === 'restricted' ? [220, 38, 38] : [100, 116, 139];

        $cx = $pageW / 2;
        $cy = $pageH / 2;

        $pdf->StartTransform();
        $pdf->SetAlpha(0.18);
        $pdf->Rotate(-30, $cx, $cy);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('helvetica', 'B', max(36, min(72, (int) round($pageW / 12))));
        $textWidth = $pdf->GetStringWidth($text);
        $pdf->SetXY($cx - $textWidth / 2, $cy - 12);
        $pdf->Cell($textWidth, 24, $text, 0, 0, 'C');
        $pdf->SetAlpha(1.0);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->StopTransform();
    }

    /**
     * Wrap a single image into a 1-page PDF and return its absolute path.
     */
    public function imageToPdf(string $absoluteImagePath): string
    {
        if (! is_file($absoluteImagePath) || ! is_readable($absoluteImagePath)) {
            throw new \RuntimeException("Image not readable: {$absoluteImagePath}");
        }
        $info = @getimagesize($absoluteImagePath);
        if ($info === false) {
            // Corrupt / unsupported image — fail cleanly so the controller can
            // surface a 422 instead of silently producing a blank A4 page.
            throw new \RuntimeException('Image is corrupt or its format is unsupported.');
        }
        [$w, $h] = $info;
        // A4 portrait if image is portrait-shaped, landscape otherwise.
        $orientation = $w > $h ? 'L' : 'P';
        $pdf = new \TCPDF($orientation, 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pageW = $pdf->getPageWidth() - 20;
        $pageH = $pdf->getPageHeight() - 20;
        $pdf->Image($absoluteImagePath, 10, 10, $pageW, $pageH, '', '', '', false, 300, '', false, false, 0, 'CT');

        $out = tempnam(sys_get_temp_dir(), 'doc') . '.pdf';
        $pdf->Output($out, 'F');
        return $out;
    }

    private function drawAnnotation(Fpdi $pdf, $annotation, float $pageW, float $pageH): void
    {
        $x = $pageW * ($annotation->x_pct / 100);
        $y = $pageH * ($annotation->y_pct / 100);
        $w = $pageW * ($annotation->w_pct / 100);
        $h = $pageH * ($annotation->h_pct / 100);

        $data = $annotation->data ?? [];

        if ($annotation->type->value === 'signature' || $annotation->type->value === 'initial') {
            $png = $data['png_base64'] ?? null;
            if ($png) {
                $tmp = tempnam(sys_get_temp_dir(), 'sig') . '.png';
                file_put_contents($tmp, base64_decode(preg_replace('#^data:image/png;base64,#', '', $png)));
                $pdf->Image($tmp, $x, $y, $w, $h, 'PNG');
                @unlink($tmp);
            }
            return;
        }

        if ($annotation->type->value === 'stamp') {
            if (! empty($data['png_base64'])) {
                $tmp = tempnam(sys_get_temp_dir(), 'stp') . '.png';
                file_put_contents($tmp, base64_decode(preg_replace('#^data:image/png;base64,#', '', $data['png_base64'])));
                $pdf->Image($tmp, $x, $y, $w, $h, 'PNG');
                @unlink($tmp);
                return;
            }
            // text stamp
            $text  = $data['text']  ?? 'STAMP';
            $color = $data['color'] ?? '#cc0000';
            [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
            $pdf->SetTextColor($r, $g, $b);
            $pdf->SetDrawColor($r, $g, $b);
            $pdf->SetLineWidth(0.8);
            $pdf->Rect($x, $y, $w, $h);
            $pdf->SetFont('helvetica', 'B', max(8, $h * 2.5));
            $pdf->SetXY($x, $y + ($h / 4));
            $pdf->Cell($w, $h, $text, 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetDrawColor(0, 0, 0);
            return;
        }

        if ($annotation->type->value === 'text') {
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x, $y);
            $pdf->SetFont('helvetica', '', max(8, $h * 2));
            $pdf->MultiCell($w, $h, $data['text'] ?? '', 0, 'L');
        }
    }

    private function drawTemplateWatermark(Fpdi $pdf, \App\Models\WatermarkTemplate $tpl, float $pageW, float $pageH): void
    {
        $cx = $pageW / 2;
        $cy = $pageH / 2;

        $pdf->StartTransform();
        $pdf->SetAlpha((float) $tpl->opacity);
        $pdf->Rotate((int) $tpl->angle_deg, $cx, $cy);

        if ($tpl->type === 'image' && $tpl->storage_path) {
            $abs = Storage::disk('local')->path($tpl->storage_path);
            $w = $pageW * 0.6;
            $pdf->Image($abs, $cx - $w / 2, $cy - $w / 2, $w, 0, 'PNG');
        } else {
            $text  = (string) ($tpl->text ?? 'WATERMARK');
            $color = $tpl->color ?? '#dc2626';
            [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
            $pdf->SetTextColor($r, $g, $b);
            $size = $tpl->font_size_hint ?: max(36, min(72, (int) round($pageW / 12)));
            $pdf->SetFont('helvetica', 'B', $size);
            $textWidth = $pdf->GetStringWidth($text);
            $pdf->SetXY($cx - $textWidth / 2, $cy - 12);
            $pdf->Cell($textWidth, 24, $text, 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }

        $pdf->SetAlpha(1.0);
        $pdf->StopTransform();
    }

    private function annotationHash($annotations): string
    {
        $payload = $annotations->map(fn ($a) => [
            'id'   => $a->id,
            'type' => $a->type->value,
            'page' => $a->page,
            'x'    => $a->x_pct,
            'y'    => $a->y_pct,
            'w'    => $a->w_pct,
            'h'    => $a->h_pct,
            'data' => $a->data,
        ])->toJson();
        return substr(hash('sha256', $payload), 0, 16);
    }
}
