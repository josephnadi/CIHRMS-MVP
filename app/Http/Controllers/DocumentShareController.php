<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DocumentShareAudience;
use App\Http\Requests\Documents\ShareDocumentRequest;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Services\DocumentShareService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentShareController extends Controller
{
    public function __construct(private readonly DocumentShareService $shares)
    {
    }

    public function store(ShareDocumentRequest $request, Document $document): RedirectResponse
    {
        // Org-wide visibility is privileged — owners alone cannot grant it
        // unless they also hold documents.share_organization. documents.manage
        // implies the right (super_admin / hr_admin always pass).
        $audience = DocumentShareAudience::from((string) $request->input('audience_type'));
        if ($audience === DocumentShareAudience::Organization) {
            $user = $request->user();
            if (! $user->hasPermission('documents.share_organization') && ! $user->hasPermission('documents.manage')) {
                abort(403, 'You do not have permission to share documents with the entire organization.');
            }
        }

        try {
            $this->shares->grant($document, $request->validated(), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['audience_type' => $e->getMessage()]);
        }

        return back()->with('flash.success', 'Document shared.');
    }

    public function destroy(Document $document, DocumentShare $share, Request $request): RedirectResponse
    {
        // Same gate as creation — owner or documents.manage can revoke.
        $this->authorize('share', $document);

        // Sanity: ensure the share belongs to the URL-bound document.
        abort_unless($share->document_id === $document->id, 404);

        $this->shares->revoke($share, $request->user());

        return back()->with('flash.success', 'Share revoked.');
    }
}
