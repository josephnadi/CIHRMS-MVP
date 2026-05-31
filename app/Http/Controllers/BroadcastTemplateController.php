<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BroadcastAudienceType;
use App\Http\Requests\Broadcast\StoreBroadcastTemplateRequest;
use App\Http\Requests\Broadcast\UpdateBroadcastTemplateRequest;
use App\Models\BroadcastTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('broadcasts.view'), 403);

        return Inertia::render('Messaging/Templates/Index', [
            'activeModule'  => 'messaging-templates',
            'templates'     => BroadcastTemplate::with('creator:id,name')->latest()->paginate(25),
            'audienceTypes' => collect(BroadcastAudienceType::cases())->map(fn ($t) => [
                'value'       => $t->value,
                'label'       => str($t->name)->headline()->toString(),
                'allowedVars' => $t->allowedVariables(),
            ]),
        ]);
    }

    public function store(StoreBroadcastTemplateRequest $request): RedirectResponse
    {
        BroadcastTemplate::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);
        return back()->with('success', 'Template created.');
    }

    public function update(UpdateBroadcastTemplateRequest $request, BroadcastTemplate $template): RedirectResponse
    {
        $template->update($request->validated());
        return back()->with('success', 'Template updated.');
    }

    public function destroy(Request $request, BroadcastTemplate $template): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('broadcasts.manage'), 403);
        $template->delete();
        return back()->with('success', 'Template deleted.');
    }
}
