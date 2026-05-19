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

    public function archive(Document $doc, User $by): Document
    {
        $doc->update(['status' => DocumentStatus::Archived]);
        $this->logEvent($doc, $by, DocumentEventType::Archived);
        return $doc;
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
