<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ]);

        $event = AnalyticsEvent::create([
            'user_id' => $request->user()?->id,
            'event' => $data['event'],
            'meta' => $data['meta'] ?? [],
        ]);

        return response()->json($event, 201);
    }
}
