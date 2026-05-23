<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentAssets\StoreWatermarkRequest;
use App\Http\Resources\WatermarkTemplateResource;
use App\Models\WatermarkTemplate;
use App\Services\WatermarkTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WatermarkTemplateController extends Controller
{
    public function __construct(private readonly WatermarkTemplateService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', WatermarkTemplate::class);
        $user = $request->user();
        $departmentId = $user->employee?->department_id;

        $items = WatermarkTemplate::query()
            ->where(function ($q) use ($user, $departmentId) {
                $q->where(fn ($x) => $x->where('owner_scope', 'personal')->where('owner_id', $user->id))
                  ->orWhere(fn ($x) => $x->where('owner_scope', 'department')->where('owner_id', $departmentId))
                  ->orWhere('owner_scope', 'organization');
            })
            ->latest()
            ->get();

        return Inertia::render('Settings/Watermarks', [
            'templates'    => WatermarkTemplateResource::collection($items),
            'canManageOrg' => $user->hasPermission('document_assets.manage'),
            'departmentId' => $departmentId,
            'activeModule' => 'settings',
        ]);
    }

    public function store(StoreWatermarkRequest $request)
    {
        $tpl = $this->service->store($request->validated(), $request->file('file'), $request->user());
        return back()->with('flash.success', "Watermark \"{$tpl->name}\" created.");
    }

    public function destroy(WatermarkTemplate $template)
    {
        $this->authorize('delete', $template);
        $this->service->delete($template);
        return back()->with('flash.success', 'Watermark removed.');
    }

    public function preview(WatermarkTemplate $template): BinaryFileResponse
    {
        $this->authorize('viewAny', WatermarkTemplate::class);
        abort_unless($template->type === 'image' && $template->storage_path, 404);
        return response()->file(Storage::disk('local')->path($template->storage_path));
    }
}
