<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastStatus;
use App\Http\Requests\Broadcast\StoreBroadcastRequest;
use App\Models\Broadcast;
use App\Models\BroadcastTemplate;
use App\Services\Messaging\Broadcasts\AudienceResolver;
use App\Services\Messaging\Broadcasts\BroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    public function __construct(
        private readonly BroadcastService $service,
        private readonly AudienceResolver $resolver,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('broadcasts.view'), 403);

        $broadcasts = Broadcast::with('creator:id,name')
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Messaging/Broadcasts/Index', [
            'activeModule' => 'messaging-broadcasts',
            'broadcasts'   => $broadcasts,
            'filters'      => $request->only('status'),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('broadcasts.manage'), 403);

        return Inertia::render('Messaging/Broadcasts/Create', [
            'activeModule' => 'messaging-broadcasts',
            'audienceTypes' => collect(BroadcastAudienceType::cases())->map(fn ($t) => [
                'value'  => $t->value,
                'label'  => str($t->name)->headline()->toString(),
                'allowedVars' => $t->allowedVariables(),
            ]),
            'templates' => BroadcastTemplate::where('is_active', true)
                ->get(['id', 'name', 'audience_type', 'sms_body', 'mail_subject', 'mail_body']),
            'canBypassThrottle' => $request->user()->hasPermission('broadcasts.bypass_throttle'),
        ]);
    }

    public function store(StoreBroadcastRequest $request): RedirectResponse
    {
        $broadcast = Broadcast::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'status'     => $request->scheduled_at
                ? BroadcastStatus::Scheduled->value
                : BroadcastStatus::Queued->value,
        ]);

        $this->service->queue($broadcast);

        return redirect()->route('messaging.broadcasts.show', $broadcast)
            ->with('success', "Broadcast '{$broadcast->title}' queued.");
    }

    public function show(Request $request, Broadcast $broadcast): Response
    {
        abort_unless($request->user()->hasPermission('broadcasts.view'), 403);

        $recipients = $broadcast->recipients()
            ->paginate(50);

        return Inertia::render('Messaging/Broadcasts/Show', [
            'activeModule' => 'messaging-broadcasts',
            'broadcast'    => $broadcast->loadMissing('creator:id,name', 'template'),
            'recipients'   => $recipients,
        ]);
    }

    public function cancel(Request $request, Broadcast $broadcast): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('broadcasts.manage'), 403);

        try {
            $this->service->cancel($broadcast);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return back()->with('success', 'Broadcast cancelled.');
    }

    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('broadcasts.manage'), 403);

        $type = BroadcastAudienceType::from($request->input('audience_type'));
        $params = $request->input('audience_params', []);

        $builder = $this->resolver->resolve($type, is_array($params) ? $params : []);
        $count = $builder->count();
        $sample = $builder->limit(10)->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name ?? '—']);

        return response()->json(['count' => $count, 'sample' => $sample]);
    }
}
