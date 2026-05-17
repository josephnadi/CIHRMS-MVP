<?php

namespace App\Http\Controllers;

use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(): Response
    {
        $announcements = Announcement::with('author:id,name')
            ->orderByDesc('pinned')
            ->orderByDesc('created_at')
            ->paginate(20);

        // Snapshot stats for the analytical band on the redesigned page
        $now = now();
        $all = Announcement::query();

        $stats = [
            'total'      => (clone $all)->count(),
            'active_now' => (clone $all)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                ->count(),
            'pinned'     => (clone $all)->where('pinned', true)->where('is_active', true)->count(),
            'scheduled'  => (clone $all)->where('is_active', true)
                ->whereNotNull('starts_at')->where('starts_at', '>', $now)->count(),
            'expired'    => (clone $all)->whereNotNull('ends_at')->where('ends_at', '<', $now)->count(),
        ];

        // Composition by type — feeds the donut + filter chips
        $typeBreakdown = Announcement::query()
            ->selectRaw('type, COUNT(*) as c')
            ->groupBy('type')
            ->pluck('c', 'type')
            ->all();

        $severityBreakdown = Announcement::query()
            ->selectRaw('severity, COUNT(*) as c')
            ->groupBy('severity')
            ->pluck('c', 'severity')
            ->all();

        return Inertia::render('Announcements/Index', [
            'announcements'     => AnnouncementResource::collection($announcements),
            'stats'             => $stats,
            'typeBreakdown'     => $typeBreakdown,
            'severityBreakdown' => $severityBreakdown,
        ]);
    }

    public function store(StoreAnnouncementRequest $request): RedirectResponse
    {
        Announcement::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Notice published.');
    }

    public function update(StoreAnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($request->validated());

        return back()->with('success', 'Notice updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        abort_unless(request()->user()?->hasPermission('announcements.manage'), 403);

        $announcement->delete();

        return back()->with('success', 'Notice removed.');
    }
}
