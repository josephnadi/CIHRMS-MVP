<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Reveals the identity of the token holder and the abilities (scopes) on
     * the active token. Useful for partner debugging — pings this first to
     * confirm credentials before issuing real API calls.
     */
    public function show(Request $request): JsonResponse
    {
        $user  = $request->user();
        $token = $user?->currentAccessToken();

        return response()->json([
            'user' => [
                'id'    => $user?->id,
                'name'  => $user?->name,
                'email' => $user?->email,
                'role'  => $user?->role?->value ?? $user?->role,
            ],
            'token' => [
                'id'         => $token?->id,
                'name'       => $token?->name,
                'abilities'  => $token?->abilities ?? [],
                'last_used'  => optional($token?->last_used_at)->toIso8601String(),
            ],
        ]);
    }
}
