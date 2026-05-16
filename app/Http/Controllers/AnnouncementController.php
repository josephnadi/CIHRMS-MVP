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

        return Inertia::render('Announcements/Index', [
            'announcements' => AnnouncementResource::collection($announcements),
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

    public function destroy(Announcement $announcement): RedirectResponse
    {
        abort_unless(request()->user()?->hasPermission('announcements.manage'), 403);

        $announcement->delete();

        return back()->with('success', 'Notice removed.');
    }
}
