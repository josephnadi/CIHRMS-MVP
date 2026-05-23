<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentAssets\StoreLetterheadRequest;
use App\Http\Resources\LetterheadTemplateResource;
use App\Models\LetterheadTemplate;
use App\Services\LetterheadTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LetterheadTemplateController extends Controller
{
    public function __construct(private readonly LetterheadTemplateService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', LetterheadTemplate::class);
        $user = $request->user();
        $departmentId = $user->employee?->department_id;

        $items = LetterheadTemplate::query()
            ->where(function ($q) use ($user, $departmentId) {
                $q->where(fn ($x) => $x->where('owner_scope', 'personal')->where('owner_id', $user->id))
                  ->orWhere(fn ($x) => $x->where('owner_scope', 'department')->where('owner_id', $departmentId))
                  ->orWhere('owner_scope', 'organization');
            })
            ->latest()
            ->get();

        return Inertia::render('Settings/Letterheads', [
            'templates'    => LetterheadTemplateResource::collection($items),
            'canManageOrg' => $user->hasPermission('document_assets.manage'),
            'departmentId' => $departmentId,
            'activeModule' => 'settings',
        ]);
    }

    public function store(StoreLetterheadRequest $request)
    {
        $tpl = $this->service->store($request->validated(), $request->file('file'), $request->user());
        return back()->with('flash.success', "Letterhead \"{$tpl->name}\" uploaded.");
    }

    public function destroy(LetterheadTemplate $template)
    {
        $this->authorize('delete', $template);
        $this->service->delete($template);
        return back()->with('flash.success', 'Letterhead removed.');
    }

    public function preview(LetterheadTemplate $template): BinaryFileResponse
    {
        $this->authorize('viewAny', LetterheadTemplate::class);
        $path = Storage::disk('local')->path($template->storage_path);
        return response()->file($path);
    }
}
