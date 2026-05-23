<?php

namespace App\Http\Controllers;

use App\Enums\DocumentConfidentiality;
use App\Enums\DocumentEventType;
use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Exceptions\ConversionNotSupportedException;
use App\Http\Requests\Documents\ActOnRouteRequest;
use App\Http\Requests\Documents\AddVersionRequest;
use App\Http\Requests\Documents\AnnotateDocumentRequest;
use App\Http\Requests\Documents\ComposeDocumentRequest;
use App\Http\Requests\Documents\RouteDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UpdateDocumentRequest;
use App\Http\Resources\DocumentAnnotationResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Services\DocumentComposerService;
use App\Services\DocumentConversionService;
use App\Services\DocumentRenderService;
use App\Services\DocumentRoutingService;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    public function __construct(
        private DocumentService $docs,
        private DocumentRoutingService $routing,
        private DocumentRenderService $render,
        private DocumentConversionService $conversion,
        private DocumentComposerService $composer,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);
        $user = $request->user();
        $tab  = $request->string('tab', 'all')->toString();

        $base = Document::query()->with(['owner', 'currentVersion']);

        $base = match ($tab) {
            'inbox'   => $base->whereHas('routes', fn ($q) => $q
                            ->where('to_user_id', $user->id)
                            ->where('status', DocumentRouteStatus::InProgress->value)),
            'sent'    => $base->where('owner_id', $user->id)
                              ->whereIn('status', [DocumentStatus::InReview->value, DocumentStatus::Completed->value]),
            'drafts'  => $base->where('owner_id', $user->id)
                              ->where('status', DocumentStatus::Draft->value),
            'archive' => $base->where('status', DocumentStatus::Archived->value),
            default   => $base->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                  ->orWhereHas('routes', fn ($r) => $r->where('to_user_id', $user->id));
            }),
        };

        if ($q = $request->string('q')->toString()) {
            $base->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                  ->orWhere('ref_no', 'like', "%{$q}%");
            });
        }

        $documents = $base->latest()->paginate(20)->withQueryString();

        $inboxCount = DocumentRoute::query()
            ->where('to_user_id', $user->id)
            ->where('status', DocumentRouteStatus::InProgress->value)
            ->count();

        return Inertia::render('Documents/Index', [
            'documents'    => DocumentResource::collection($documents),
            'tab'          => $tab,
            'filters'      => ['q' => $request->string('q')->toString()],
            'inboxCount'   => $inboxCount,
            'activeModule' => 'documents',
        ]);
    }

    public function store(StoreDocumentRequest $request)
    {
        $doc = $this->docs->upload(
            $request->file('file'),
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('documents.show', $doc->uuid)
            ->with('flash.success', "Document {$doc->ref_no} uploaded.");
    }

    /**
     * Render the in-portal composer. The user writes HTML via the
     * contenteditable surface and decides whether to attach the official
     * institutional letterhead. On submit the HTML is rendered to PDF
     * server-side and registered as a normal Document in Draft status.
     */
    public function compose(Request $request): Response
    {
        $this->authorize('create', Document::class);

        return Inertia::render('Documents/Compose', [
            'activeModule' => 'documents',
        ]);
    }

    public function storeComposed(ComposeDocumentRequest $request)
    {
        $doc = $this->composer->compose($request->validated(), $request->user());

        return redirect()->route('documents.show', $doc->uuid)
            ->with('flash.success', "Document {$doc->ref_no} composed.");
    }

    public function show(Document $document): Response
    {
        $this->authorize('view', $document);

        $document->load([
            'owner', 'currentVersion', 'versions',
            'routes.fromUser', 'routes.toUser',
            'annotations.user',
            'annotations.route',
            'events.actor',
            'shares',
        ]);

        // Pre-signed download URLs (5-min TTL). The download route is protected
        // by the `signed` middleware, so the page must hand the frontend ready-
        // to-use URLs that carry a valid signature.
        $downloadUrls = [
            'original' => URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
                'document' => $document->uuid,
                'version'  => $document->currentVersion?->version_no,
            ]),
            'burned'   => URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
                'document' => $document->uuid,
                'burned'   => 1,
            ]),
        ];

        return Inertia::render('Documents/Show', [
            'document'     => new DocumentResource($document),
            'downloadUrls' => $downloadUrls,
            'activeModule' => 'documents',
        ]);
    }

    public function addVersion(AddVersionRequest $request, Document $document)
    {
        $this->docs->addVersion(
            $document,
            $request->file('file'),
            $request->user(),
            $request->string('notes')->toString() ?: null,
        );

        return back()->with('flash.success', 'New version uploaded.');
    }

    public function route(RouteDocumentRequest $request, Document $document)
    {
        $this->routing->route($document, $request->validated('recipients'));
        return back()->with('flash.success', 'Document routed.');
    }

    public function annotate(AnnotateDocumentRequest $request, Document $document)
    {
        $activeRoute = $document->routes()
            ->where('to_user_id', $request->user()->id)
            ->where('status', DocumentRouteStatus::InProgress->value)
            ->first();

        $annotation = $this->docs->saveAnnotation($document, $activeRoute, $request->user(), $request->validated());

        return back()->with([
            'flash.success' => 'Annotation saved.',
            'annotation'    => (new DocumentAnnotationResource($annotation))->resolve(),
        ]);
    }

    public function removeAnnotation(Document $document, int $annotationId, Request $request)
    {
        $this->authorize('annotate', $document);
        $a = $document->annotations()->where('id', $annotationId)->firstOrFail();
        abort_unless($a->user_id === $request->user()->id, 403);
        $this->docs->removeAnnotation($a, $request->user());
        return back()->with('flash.success', 'Annotation removed.');
    }

    public function updateAnnotation(
        \App\Http\Requests\Documents\MoveAnnotationRequest $request,
        Document $document,
        \App\Models\DocumentAnnotation $annotation,
    ) {
        abort_unless($annotation->document_id === $document->id, 404);
        $updated = $this->docs->moveAnnotation($annotation, $request->validated(), $request->user());
        return back()->with([
            'flash.success' => 'Annotation updated.',
            'annotation'    => (new \App\Http\Resources\DocumentAnnotationResource($updated))->resolve(),
        ]);
    }

    public function act(ActOnRouteRequest $request, Document $document, DocumentRoute $route)
    {
        $this->routing->act(
            $route,
            $request->validated('decision'),
            $request->validated('comment'),
            $request->user(),
        );

        return back()->with('flash.success', 'Decision recorded.');
    }

    public function withdraw(Document $document, Request $request)
    {
        $this->authorize('withdraw', $document);
        $this->routing->withdraw($document, $request->user());
        return back()->with('flash.success', 'Document withdrawn.');
    }

    public function archive(Document $document, Request $request)
    {
        $this->authorize('manage', Document::class);
        $this->docs->archive($document, $request->user());
        return back()->with('flash.success', 'Document archived.');
    }

    /**
     * Documents v2 — Phase 1. Metadata-only edit on Draft documents.
     * Authorisation is enforced inside UpdateDocumentRequest via the
     * DocumentPolicy::update gate.
     */
    public function update(UpdateDocumentRequest $request, Document $document)
    {
        $this->docs->updateMetadata($document, $request->validated(), $request->user());
        return back()->with('flash.success', 'Document updated.');
    }

    /**
     * Documents v2 — Phase 1. Soft delete. Owner-on-Draft OR documents.manage.
     */
    public function destroy(Document $document, Request $request)
    {
        $this->authorize('delete', $document);
        $this->docs->softDelete($document, $request->user());
        return redirect()->route('documents.index')
            ->with('flash.success', "Document {$document->ref_no} deleted.");
    }

    public function download(Document $document, Request $request): BinaryFileResponse
    {
        $this->authorize('view', $document);

        $version = $request->integer('version')
            ? $document->versions()->where('version_no', $request->integer('version'))->firstOrFail()
            : $document->currentVersion;

        abort_unless($version, 404);

        // Restricted documents are NEVER served as the raw original — every
        // download is a freshly-burned PDF stamped with viewer + timestamp so
        // a leaked copy is traceable back to who pulled it.
        $isRestricted = $document->confidentiality === DocumentConfidentiality::Restricted;
        $burned = $isRestricted || $request->boolean('burned', false);

        if ($burned) {
            $watermark = $isRestricted ? [
                'tone' => 'restricted',
                'text' => sprintf(
                    'RESTRICTED · %s · %s',
                    $request->user()->name ?? 'unknown',
                    now()->format('Y-m-d H:i'),
                ),
            ] : null;
            $path = $this->render->burn($version, $watermark);
        } else {
            $path = Storage::disk('local')->path($version->storage_path);
        }

        // ref_no carries slashes (e.g. CIHRMS/DOC/2026/0001) which Symfony's
        // Content-Disposition validator rejects. Flatten to a filesystem-safe
        // slug for the download filename only.
        $safeRefNo = str_replace(['/', '\\'], '-', $document->ref_no);
        $name = $isRestricted
            ? "{$safeRefNo}-restricted.pdf"
            : ($burned ? "{$safeRefNo}-burned.pdf" : $version->original_name);

        $this->docs->logEvent($document, $request->user(), DocumentEventType::Downloaded, [
            'version_id'  => $version->id,
            'burned'      => $burned,
            'watermarked' => $isRestricted,
        ]);

        return response()->download($path, $name);
    }

    /**
     * Typeahead for the route-recipient picker (F-4). Returns up to 20 users
     * whose name OR staff_id matches the query, excluding the requesting user.
     * Requires `documents.view` so it never leaks the staff directory to
     * unprivileged accounts.
     */
    public function searchUsers(Request $request)
    {
        $this->authorize('viewAny', Document::class);

        $q = trim($request->string('q')->toString());
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $users = \App\Models\User::query()
            ->where('id', '!=', $request->user()->id)
            ->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('staff_id', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'staff_id']);

        return response()->json(['data' => $users]);
    }

    public function convert(Document $document, Request $request)
    {
        $this->authorize('view', $document);
        $to = $request->string('to', 'pdf')->toString();

        try {
            $path = $this->conversion->convert($document->currentVersion, $to);
            return response()->download($path, $document->ref_no . '.' . $to);
        } catch (ConversionNotSupportedException $e) {
            return response()->json(['message' => $e->getMessage()], 501);
        }
    }
}
