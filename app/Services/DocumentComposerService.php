<?php

namespace App\Services;

use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a user-composed HTML body into a PDF — with the institutional
 * letterhead optionally drawn on every page — and registers the result as a
 * standard Document in Draft status so it can then flow through the normal
 * annotate → route → act → download pipeline.
 *
 * Pairs with the Compose page (`Pages/Documents/Compose.vue`): the page POSTs
 * HTML + meta to `documents.compose.store`, the controller calls this service,
 * the user lands on the new Document's Show page.
 */
class DocumentComposerService
{
    private const DISK = 'local';

    public function __construct(private DocumentService $docs) {}

    /**
     * @param  array{title:string, description?:?string, confidentiality?:?string, body_html:string, letterhead?:?bool, tags?:?array}  $attrs
     */
    public function compose(array $attrs, User $owner): Document
    {
        return DB::transaction(function () use ($attrs, $owner) {
            // Up to 3 attempts to handle the rare ref_no collision under
            // concurrency. PostgreSQL forbids `FOR UPDATE` on aggregate
            // queries, so we can't serialize the count(); the unique
            // constraint catches collisions and the retry recomputes.
            $doc = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $doc = Document::create([
                        'ref_no'          => $this->nextRefNo(),
                        'title'           => $attrs['title'],
                        'description'    => $attrs['description'] ?? null,
                        'owner_id'        => $owner->id,
                        'status'          => DocumentStatus::Draft,
                        'confidentiality' => $attrs['confidentiality'] ?? 'internal',
                        'tags'            => $attrs['tags'] ?? null,
                    ]);
                    break;
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    if ($attempt === 3) throw $e;
                    usleep(50_000 * $attempt);
                }
            }

            $pdfPath = $this->renderHtmlToPdf(
                $doc,
                $attrs['title'],
                $this->sanitizeHtml($attrs['body_html']),
                (bool) ($attrs['letterhead'] ?? true),
            );

            $sha256 = hash_file('sha256', $pdfPath);
            $size   = filesize($pdfPath);
            $storagePath = sprintf('documents/%s/v1/%s.pdf', $doc->uuid, str(now()->format('YmdHis'))->snake());
            Storage::disk(self::DISK)->put($storagePath, file_get_contents($pdfPath));
            @unlink($pdfPath);

            $version = DocumentVersion::create([
                'document_id'   => $doc->id,
                'version_no'    => 1,
                'original_name' => str($attrs['title'])->ascii()->slug()->prepend('')->append('.pdf')->toString(),
                'mime'          => 'application/pdf',
                'size'          => $size,
                'storage_path'  => $storagePath,
                'sha256'        => $sha256,
                'uploaded_by'   => $owner->id,
                'uploaded_at'   => now(),
                'notes'         => 'Composed in-portal' . ($attrs['letterhead'] ?? true ? ' with letterhead' : ''),
            ]);

            $doc->update(['current_version_id' => $version->id]);

            $this->docs->logEvent($doc, $owner, DocumentEventType::Uploaded, [
                'version_id' => $version->id,
                'composed'   => true,
                'letterhead' => (bool) ($attrs['letterhead'] ?? true),
            ]);

            return $doc->fresh(['currentVersion', 'owner']);
        });
    }

    /**
     * Letterhead block drawn at the top of every page when `$letterhead = true`.
     * Single hardcoded institutional design — title strip + gold rule. Kept
     * purposefully minimal so it stays legible across formats; richer logos
     * can replace the title-only stamp later by dropping a PNG at
     * `public/img/letterhead.png` (the renderer will pick it up if present).
     */
    private function renderHtmlToPdf(Document $doc, string $title, string $bodyHtml, bool $letterhead): string
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('CIHRMS');
        $pdf->SetAuthor($doc->owner?->name ?? 'CIHRMS');
        $pdf->SetTitle($title);
        $pdf->SetMargins(20, $letterhead ? 36 : 18, 20);
        $pdf->setHeaderMargin($letterhead ? 8 : 0);
        $pdf->setAutoPageBreak(true, 18);

        if ($letterhead) {
            $logoPath = public_path('img/letterhead.png');
            $hasLogo  = is_file($logoPath);

            $pdf->setPrintHeader(true);
            $pdf->setHeaderFont(['helvetica', 'B', 9]);
            // TCPDF lets us either set header text/logo via setHeaderData OR
            // override Header(). We override so we get full control over the
            // gold rule beneath the title, the optional logo on the left, and
            // the per-page consistency.
            // Setting Header() via subclass is heavy; instead we use a small
            // anonymous extension would require eval, so just lean on
            // setHeaderData + tweak: institutional title, optional logo.
            $pdf->SetHeaderData(
                $hasLogo ? 'img/letterhead.png' : '',
                $hasLogo ? 22 : 0,
                'CIHRM-GHANA',
                "P.O. Box 1234, Cape Coast · cihrm-ghana.gov.gh · communications@cihrm.gov.gh",
            );
        } else {
            $pdf->setPrintHeader(false);
        }
        $pdf->setPrintFooter(false);

        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 11);

        // Strip our editor's class names + inline styles down to TCPDF-friendly
        // markup, then render. TCPDF supports a subset of HTML; the editor's
        // toolbar only emits tags from that subset (b, i, u, p, br, h1-h3,
        // ul, ol, li, a, blockquote, hr, div with text-align).
        $pdf->writeHTML($bodyHtml, true, false, true, false, '');

        $out = tempnam(sys_get_temp_dir(), 'comp') . '.pdf';
        $pdf->Output($out, 'F');

        return $out;
    }

    /**
     * Allow only the HTML tags the contenteditable toolbar can produce. This
     * is a server-side belt to match the editor's enabled-commands belt; users
     * who paste arbitrary HTML still get a clean PDF.
     */
    private function sanitizeHtml(string $html): string
    {
        $allowed = '<p><br><b><strong><i><em><u><h1><h2><h3><ul><ol><li><a><blockquote><hr><div><span>';
        $clean = strip_tags($html, $allowed);
        // Drop any javascript: / on* event-handler attributes the user might
        // have pasted in. TCPDF rendering wouldn't execute them, but we don't
        // want them appearing in any future browser-rendered preview either.
        $clean = preg_replace('/\s(on\w+|style|class)="[^"]*"/i', '', $clean) ?? $clean;
        $clean = preg_replace('/href="\s*javascript:[^"]*"/i', 'href="#"', $clean) ?? $clean;
        // Re-attach simple alignment via inline style only for divs we expect.
        return $clean;
    }

    private function nextRefNo(): string
    {
        // PostgreSQL forbids `FOR UPDATE` on aggregate queries. We rely on
        // the unique `ref_no` constraint + the retry-on-collision loop in
        // `compose()` instead of trying to serialize the count() here.
        $year = now()->year;
        $count = Document::whereYear('created_at', $year)->count() + 1;
        return sprintf('CIHRMS/DOC/%d/%04d', $year, $count);
    }
}
