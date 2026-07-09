<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Enums\IncomingInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PostIncomingInvoiceRequest;
use App\Http\Requests\Finance\ReturnIncomingInvoiceRequest;
use App\Http\Requests\Finance\StoreIncomingInvoiceRequest;
use App\Http\Requests\Finance\UpdateIncomingInvoiceRequest;
use App\Http\Requests\Finance\VetIncomingInvoiceRequest;
use App\Http\Resources\Finance\IncomingInvoiceResource;
use App\Models\GlAccount;
use App\Models\IncomingInvoice;
use App\Models\Vendor;
use App\Services\Finance\IncomingInvoiceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncomingInvoiceController extends Controller
{
    public function __construct(private readonly IncomingInvoiceService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'search']);
        $q = IncomingInvoice::query()->with('department:id,name');
        if (! empty($filters['status'])) $q->where('status', $filters['status']);
        if (! empty($filters['search'])) $q->where('vendor_name', 'like', '%'.$filters['search'].'%');

        $invoices = $q->orderByDesc('created_at')->paginate(50)->withQueryString();

        return Inertia::render('Auditor/IncomingInvoices/Index', [
            'activeModule' => 'auditor-incoming-invoices',
            'invoices'     => IncomingInvoiceResource::collection($invoices),
            'filters'      => $filters,
            'statuses'     => collect(IncomingInvoiceStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Auditor/IncomingInvoices/Create', [
            'activeModule' => 'auditor-incoming-invoices',
        ]);
    }

    public function show(IncomingInvoice $incomingInvoice, Request $request): Response
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.view'), 403);
        $incomingInvoice->load(['department', 'attachments', 'events.actor']);

        return Inertia::render('Auditor/IncomingInvoices/Show', [
            'activeModule'    => 'auditor-incoming-invoices',
            'invoice'         => (new IncomingInvoiceResource($incomingInvoice))->resolve(),
            'vendors'         => Vendor::active()->orderBy('name')->get(['id', 'code', 'name']),
            'expenseAccounts' => GlAccount::ofType('expense')->active()->orderBy('code')->get(['id', 'code', 'name']),
            'can'             => [
                'vet'     => $request->user()->hasPermission('incoming_invoices.vet'),
                'approve' => $request->user()->hasPermission('incoming_invoices.approve'),
                'post'    => $request->user()->hasPermission('incoming_invoices.post'),
                'submit'  => $request->user()->hasPermission('incoming_invoices.submit'),
            ],
        ]);
    }

    public function store(StoreIncomingInvoiceRequest $request): RedirectResponse
    {
        $this->service->create($this->withAttachments($request), $request->user());
        return redirect()->route('auditor.incoming-invoices.index')->with('success', 'Invoice submitted to intake.');
    }

    public function update(UpdateIncomingInvoiceRequest $request, IncomingInvoice $incomingInvoice): RedirectResponse
    {
        try {
            $this->service->update($incomingInvoice, $this->withAttachments($request), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice updated.');
    }

    public function submit(IncomingInvoice $incomingInvoice, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.submit'), 403);
        try {
            $this->service->submit($incomingInvoice, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice submitted for vetting.');
    }

    public function vet(VetIncomingInvoiceRequest $request, IncomingInvoice $incomingInvoice): RedirectResponse
    {
        try {
            $this->service->vetAccept($incomingInvoice, $request->user(), $request->validated('notes'));
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice vetted — sent to CEO.');
    }

    public function vetReturn(ReturnIncomingInvoiceRequest $request, IncomingInvoice $incomingInvoice): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.vet'), 403);
        try {
            $this->service->vetReturn($incomingInvoice, $request->user(), $request->validated('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice returned to submitter.');
    }

    public function approve(IncomingInvoice $incomingInvoice, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.approve'), 403);
        try {
            $this->service->ceoApprove($incomingInvoice, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice approved.');
    }

    public function ceoReturn(ReturnIncomingInvoiceRequest $request, IncomingInvoice $incomingInvoice): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.approve'), 403);
        try {
            $this->service->ceoReturn($incomingInvoice, $request->user(), $request->validated('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice returned to submitter.');
    }

    public function post(PostIncomingInvoiceRequest $request, IncomingInvoice $incomingInvoice): RedirectResponse
    {
        try {
            $this->service->post($incomingInvoice, $request->validated(), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice posted — vendor invoice + accrual created.');
    }

    public function download(IncomingInvoice $incomingInvoice, int $attachment, Request $request): StreamedResponse
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.view'), 403);
        $file = $incomingInvoice->attachments()->findOrFail($attachment);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    /** Store uploaded files to the private disk and fold their metadata into the payload. */
    private function withAttachments(Request $request): array
    {
        $data = $request->validated();
        $data['attachments'] = [];
        foreach ($request->file('attachments', []) as $file) {
            $data['attachments'][] = [
                'path'          => $file->store('incoming-invoices', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getMimeType(),
                'size'          => $file->getSize(),
            ];
        }
        return $data;
    }
}
