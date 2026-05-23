<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentConfidentiality;
use App\Enums\DocumentEventType;
use App\Enums\DocumentShareAudience;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Documents v2 — Phase 1. Read-only share grants distinct from the
 * sequential routing workflow.
 *
 * Confidentiality guard: a document marked `confidential` or `restricted`
 * can only be shared with individual users (audience_type=user) — never to
 * a department or the whole organization. The guard throws DomainException
 * which the controller maps to HTTP 422.
 */
class DocumentShareService
{
    public function __construct(private readonly DocumentService $documents)
    {
    }

    public function grant(Document $doc, array $attrs, User $by): DocumentShare
    {
        $audience = $attrs['audience_type'] instanceof DocumentShareAudience
            ? $attrs['audience_type']
            : DocumentShareAudience::from((string) $attrs['audience_type']);

        $audienceId = $audience === DocumentShareAudience::Organization
            ? null
            : (int) ($attrs['audience_id'] ?? 0);

        $this->assertConfidentialityAllows($doc, $audience);
        $this->assertAudienceExists($audience, $audienceId);

        return DB::transaction(function () use ($doc, $by, $audience, $audienceId, $attrs) {
            // Idempotent: if a share for the same (doc, audience_type, audience_id)
            // already exists, just refresh expires_at + grantor and return it.
            $share = DocumentShare::firstOrNew([
                'document_id'   => $doc->id,
                'audience_type' => $audience->value,
                'audience_id'   => $audienceId,
            ]);

            $share->granted_by = $by->id;
            $share->granted_at = now();
            $share->expires_at = ! empty($attrs['expires_at']) ? $attrs['expires_at'] : null;
            $share->save();

            $this->documents->logEvent($doc, $by, DocumentEventType::Shared, [
                'audience_type' => $audience->value,
                'audience_id'   => $audienceId,
            ]);

            return $share->fresh();
        });
    }

    public function revoke(DocumentShare $share, User $by): void
    {
        DB::transaction(function () use ($share, $by) {
            $doc = $share->document;
            $this->documents->logEvent($doc, $by, DocumentEventType::Unshared, [
                'audience_type' => $share->audience_type->value,
                'audience_id'   => $share->audience_id,
            ]);
            $share->delete();
        });
    }

    private function assertConfidentialityAllows(Document $doc, DocumentShareAudience $audience): void
    {
        $sensitive = in_array($doc->confidentiality, [
            DocumentConfidentiality::Confidential,
            DocumentConfidentiality::Restricted,
        ], true);

        if ($sensitive && $audience !== DocumentShareAudience::User) {
            throw new DomainException(
                "Documents marked {$doc->confidentiality->value} cannot be shared with departments or the entire organization. Share with individuals only."
            );
        }
    }

    private function assertAudienceExists(DocumentShareAudience $audience, ?int $audienceId): void
    {
        switch ($audience) {
            case DocumentShareAudience::User:
                if (! $audienceId || ! User::query()->whereKey($audienceId)->exists()) {
                    throw new DomainException('Selected user does not exist.');
                }
                break;
            case DocumentShareAudience::Department:
                if (! $audienceId || ! Department::query()->whereKey($audienceId)->exists()) {
                    throw new DomainException('Selected department does not exist.');
                }
                break;
            case DocumentShareAudience::Organization:
                // No target — audience_id must stay null.
                break;
        }
    }
}
