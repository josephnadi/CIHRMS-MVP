<?php

namespace App\Http\Controllers;

use App\Models\ApiTokenMetadata;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Admin UI for issuing and revoking Sanctum personal access tokens against
 * the v1 public API. Plaintext token shown ONCE on creation and never again.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('api.token_manage'), 403);

        $tokens = PersonalAccessToken::query()
            ->latest()
            ->paginate(20);

        $tokens->getCollection()->transform(function (PersonalAccessToken $t) {
            $meta = ApiTokenMetadata::where('token_id', $t->id)->first();
            return [
                'id'         => $t->id,
                'name'       => $t->name,
                'abilities'  => $t->abilities,
                'last_used'  => optional($t->last_used_at)->toIso8601String(),
                'created_at' => optional($t->created_at)->toIso8601String(),
                'meta' => $meta ? [
                    'purpose'    => $meta->purpose,
                    'rate_limit' => $meta->rate_limit_per_minute,
                    'expires_at' => optional($meta->expires_at)->toIso8601String(),
                    'revoked_at' => optional($meta->revoked_at)->toIso8601String(),
                    'is_usable'  => $meta->isUsable(),
                    'issued_to'  => $meta->issued_to_user_id ? User::find($meta->issued_to_user_id)?->name : null,
                ] : null,
            ];
        });

        return Inertia::render('Settings/ApiTokens/Index', [
            'tokens'       => $tokens,
            'activeModule' => 'api-tokens',
            'flash_token'  => $request->session()->get('flash_token'),
            'available_scopes' => [
                'employees:read', 'payroll:read', 'attendance:read',
                'positions:read', 'statutory:export', 'identity:read', 'identity:write',
                'webhooks:manage', '*',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('api.token_manage'), 403);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'purpose'     => ['nullable', 'string', 'max:500'],
            'abilities'   => ['required', 'array', 'min:1'],
            'abilities.*' => ['string'],
            'rate_limit'  => ['nullable', 'integer', 'min:1', 'max:6000'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'allowed_ip_cidrs' => ['nullable', 'array'],
            'issued_to_user_id'=> ['nullable', 'integer', 'exists:users,id'],
        ]);

        $issuee = $data['issued_to_user_id']
            ? User::findOrFail($data['issued_to_user_id'])
            : $request->user();

        $tokenInstance = $issuee->createToken($data['name'], $data['abilities']);
        $plaintext     = $tokenInstance->plainTextToken;
        $tokenId       = $tokenInstance->accessToken->id;

        ApiTokenMetadata::create([
            'token_id'              => $tokenId,
            'issued_to_user_id'     => $issuee->id,
            'issued_by_user_id'     => $request->user()->id,
            'purpose'               => $data['purpose'] ?? null,
            'rate_limit_per_minute' => $data['rate_limit'] ?? 60,
            'expires_at'            => isset($data['expires_in_days'])
                ? now()->addDays((int) $data['expires_in_days'])
                : null,
            'allowed_ip_cidrs'      => $data['allowed_ip_cidrs'] ?? null,
        ]);

        return redirect()->route('api-tokens.index')
            ->with('flash_token', $plaintext)
            ->with('success', 'Token created — save it now, you will not see it again.');
    }

    public function destroy(Request $request, int $tokenId): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('api.token_manage'), 403);

        $token = PersonalAccessToken::findOrFail($tokenId);
        $meta  = ApiTokenMetadata::where('token_id', $tokenId)->first();

        if ($meta) {
            $meta->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id]);
        }
        $token->delete();

        return back()->with('success', 'Token revoked.');
    }
}
