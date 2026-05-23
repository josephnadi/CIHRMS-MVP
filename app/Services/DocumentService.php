<?php

namespace App\Services;

use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentEvent;
use App\Models\DocumentRoute;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    private const DISK = 'local';

    public function upload(UploadedFile $file, array $attrs, User $owner): Document
    {
        return DB::transaction(function () use ($file, $attrs, $owner) {
            // Up to 3 attempts to handle the rare ref_no collision under concurrency.
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
                    usleep(50_000 * $attempt); // brief backoff
                }
            }

            $version = $this->storeVersion($doc, $file, $owner, versionNo: 1);
            $doc->update(['current_version_id' => $version->id]);

            $this->logEvent($doc, $owner, DocumentEventType::Uploaded, [
                'version_id'    => $version->id,
                'original_name' => $version->original_name,
            ]);

            return $doc->fresh(['currentVersion', 'owner']);
        });
    }

    public function addVersion(Document $doc, UploadedFile $file, User $by, ?string $notes = null): DocumentVersion
    {
        return DB::transaction(function () use ($doc, $file, $by, $notes) {
            $next = ($doc->versions()->max('version_no') ?? 0) + 1;
            $version = $this->storeVersion($doc, $file, $by, $next, $notes);

            $doc->update(['current_version_id' => $version->id]);

            $this->logEvent($doc, $by, DocumentEventType::VersionAdded, [
                'version_id' => $version->id,
                'version_no' => $next,
            ]);

            return $version;
        });
    }

    public function saveAnnotation(Document $doc, ?DocumentRoute $route, User $by, array $attrs): DocumentAnnotation
    {
        return DB::transaction(function () use ($doc, $route, $by, $attrs) {
            $annotation = $doc->annotations()->create([
                'version_id' => $doc->current_version_id,
                'route_id'   => $route?->id,
                'user_id'    => $by->id,
                'type'       => $attrs['type'],
                'page'       => $attrs['page'] ?? 1,
                'x_pct'      => $attrs['x_pct'],
                'y_pct'      => $attrs['y_pct'],
                'w_pct'      => $attrs['w_pct'] ?? 10,
                'h_pct'      => $attrs['h_pct'] ?? 5,
                'rotation'   => $attrs['rotation'] ?? 0,
                'data'       => $attrs['data'],
            ]);

            $eventType = match ($attrs['type']) {
                'signature', 'initial' => DocumentEventType::Signed,
                'stamp'                => DocumentEventType::Stamped,
                default                => DocumentEventType::Annotated,
            };

            $this->logEvent($doc, $by, $eventType, [
                'annotation_id' => $annotation->id,
                'page'          => $annotation->page,
                'type'          => $annotation->type->value,
            ]);

            return $annotation;
        });
    }

    public function removeAnnotation(DocumentAnnotation $annotation, User $by): void
    {
        $annotation->delete();
    }

    public function moveAnnotation(DocumentAnnotation $annotation, array $attrs, User $by): DocumentAnnotation
    {
        $fields = ['x_pct', 'y_pct', 'w_pct', 'h_pct', 'rotation'];
        $clean  = array_intersect_key($attrs, array_flip($fields));

        $before = $annotation->only($fields);
        $annotation->update($clean);
        $after  = $annotation->fresh()->only($fields);

        $posChanged = $before['x_pct'] != $after['x_pct'] || $before['y_pct'] != $after['y_pct'];
        $dimChanged = $before['w_pct'] != $after['w_pct'] || $before['h_pct'] != $after['h_pct'] || $before['rotation'] != $after['rotation'];

        if ($posChanged) {
            $this->logEvent($annotation->document, $by, DocumentEventType::AnnotationMoved, [
                'annotation_id' => $annotation->id,
                'from' => ['x' => $before['x_pct'], 'y' => $before['y_pct']],
                'to'   => ['x' => $after['x_pct'],  'y' => $after['y_pct']],
            ]);
        }
        if ($dimChanged) {
            $this->logEvent($annotation->document, $by, DocumentEventType::AnnotationResized, [
                'annotation_id' => $annotation->id,
                'from' => ['w' => $before['w_pct'], 'h' => $before['h_pct'], 'rot' => $before['rotation']],
                'to'   => ['w' => $after['w_pct'],  'h' => $after['h_pct'],  'rot' => $after['rotation']],
            ]);
        }
        return $annotation->fresh();
    }

    public function archive(Document $doc, User $by): Document
    {
        $doc->update(['status' => DocumentStatus::Archived]);
        $this->logEvent($doc, $by, DocumentEventType::Archived);
        return $doc;
    }

    /**
     * Documents v2 — Phase 1. Metadata-only edit. Allowed by policy only on
     * Draft documents; the caller is expected to have run the policy check
     * (the controller does). Logs the field diff so the event log doubles as
     * an audit trail.
     */
    public function updateMetadata(Document $doc, array $attrs, User $by): Document
    {
        $allowed = ['title', 'description', 'confidentiality', 'tags'];
        $clean = array_intersect_key($attrs, array_flip($allowed));

        $before = $doc->only($allowed);
        $doc->update($clean);
        $after = $doc->fresh()->only($allowed);

        $changed = [];
        foreach ($allowed as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changed[$field] = ['from' => $before[$field] ?? null, 'to' => $after[$field] ?? null];
            }
        }

        if (! empty($changed)) {
            $this->logEvent($doc, $by, DocumentEventType::Updated, ['changes' => $changed]);
        }

        return $doc->fresh();
    }

    /**
     * Documents v2 — Phase 1. Soft delete. The `documents` table uses
     * SoftDeletes — record stays for audit replay; lists already filter
     * trashed rows by default.
     */
    public function softDelete(Document $doc, User $by): void
    {
        $this->logEvent($doc, $by, DocumentEventType::Deleted, [
            'ref_no' => $doc->ref_no,
            'title'  => $doc->title,
        ]);
        $doc->delete();
    }

    public function logEvent(Document $doc, User $actor, DocumentEventType $type, array $payload = []): DocumentEvent
    {
        return DocumentEvent::create([
            'document_id' => $doc->id,
            'actor_id'    => $actor->id,
            'type'        => $type,
            'payload'     => $payload,
            'occurred_at' => now(),
        ]);
    }

    private function storeVersion(Document $doc, UploadedFile $file, User $by, int $versionNo, ?string $notes = null): DocumentVersion
    {
        // Hash before the upload is moved/consumed.
        $sha256 = hash_file('sha256', $file->getRealPath());
        $originalName = $file->getClientOriginalName();
        $mime = $file->getClientMimeType();
        $size = $file->getSize();

        // Safe on-disk filename: hash + original extension. Preserve original_name in DB for display.
        $safeFilename = $file->hashName();
        $path = sprintf('documents/%s/v%d/%s', $doc->uuid, $versionNo, $safeFilename);

        Storage::disk(self::DISK)->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        return DocumentVersion::create([
            'document_id'   => $doc->id,
            'version_no'    => $versionNo,
            'original_name' => $originalName,
            'mime'          => $mime,
            'size'          => $size,
            'storage_path'  => $path,
            'sha256'        => $sha256,
            'uploaded_by'   => $by->id,
            'uploaded_at'   => now(),
            'notes'         => $notes,
        ]);
    }

    private function nextRefNo(): string
    {
        // PostgreSQL forbids `FOR UPDATE` on aggregate queries (`count()`),
        // so we cannot serialize on the row read here. Concurrency safety is
        // therefore delegated to the unique `ref_no` constraint + the retry
        // loop in `upload()`: if two transactions compute the same count,
        // the second's INSERT raises a `UniqueConstraintViolationException`,
        // the retry recomputes the count (now including the first row), and
        // proceeds. Three attempts is empirically more than enough — a fourth
        // collision would mean three concurrent uploaders in the same year,
        // which is exceedingly rare for institutional documents.
        $year = now()->year;
        $count = Document::whereYear('created_at', $year)->count() + 1;
        return sprintf('CIHRMS/DOC/%d/%04d', $year, $count);
    }
}
