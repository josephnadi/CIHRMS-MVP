<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentAssets\StoreStampAssetRequest;
use App\Http\Resources\StampAssetResource;
use App\Models\StampAsset;
use App\Services\StampAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StampAssetController extends Controller
{
    public function __construct(private readonly StampAssetService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', StampAsset::class);
        $user = $request->user();
        $departmentId = $user->employee?->department_id;

        $assets = StampAsset::query()
            ->where(function ($q) use ($user, $departmentId) {
                $q->where(fn ($x) => $x->where('owner_scope', 'personal')->where('owner_id', $user->id))
                  ->orWhere(fn ($x) => $x->where('owner_scope', 'department')->where('owner_id', $departmentId))
                  ->orWhere('owner_scope', 'organization');
            })
            ->latest()
            ->get();

        return Inertia::render('Settings/Stamps', [
            'assets'       => StampAssetResource::collection($assets),
            'canManageOrg' => $user->hasPermission('document_assets.manage'),
            'departmentId' => $departmentId,
            'activeModule' => 'settings',
        ]);
    }

    public function store(StoreStampAssetRequest $request)
    {
        $asset = $this->service->store($request->validated(), $request->file('file'), $request->user());
        return back()->with('flash.success', "Stamp \"{$asset->name}\" uploaded.");
    }

    public function destroy(StampAsset $asset)
    {
        $this->authorize('delete', $asset);
        $this->service->delete($asset);
        return back()->with('flash.success', 'Stamp removed.');
    }

    public function preview(StampAsset $asset): BinaryFileResponse
    {
        $this->authorize('viewAny', StampAsset::class);
        $path = Storage::disk('local')->path($asset->storage_path);
        return response()->file($path);
    }
}
