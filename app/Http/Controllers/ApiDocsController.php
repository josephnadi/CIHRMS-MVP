<?php

namespace App\Http\Controllers;

use App\Models\WebhookSubscription;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiDocsController extends Controller
{
    /**
     * Inertia API landing page — explainer, quick-start, endpoint catalogue,
     * and a CTA into the interactive Stoplight reference.
     */
    public function show(Request $request): Response
    {
        $tokenCount    = Schema::hasTable('personal_access_tokens')
            ? PersonalAccessToken::count()
            : 0;
        $webhookCount  = Schema::hasTable('webhook_subscriptions')
            ? WebhookSubscription::where('is_active', true)->count()
            : 0;

        return Inertia::render('Settings/ApiDocs/Index', [
            'activeModule'   => 'api-docs',
            'version'        => 'v1',
            'baseUrl'        => $request->getSchemeAndHttpHost() . '/api/v1',
            'openapiYamlUrl' => $request->getSchemeAndHttpHost() . '/api/v1/openapi.yaml',
            'openapiJsonUrl' => $request->getSchemeAndHttpHost() . '/api/v1/openapi.json',
            'interactiveUrl' => route('api.docs.interactive'),
            'tokenCount'     => $tokenCount,
            'webhookCount'   => $webhookCount,
        ]);
    }

    /**
     * Legacy full-screen Stoplight Elements viewer. Kept on a separate
     * route so we can frame it inside the app shell as an iframe from the
     * Inertia landing page, while still offering a direct, full-bleed URL
     * for users who want to bookmark the reference itself.
     */
    public function interactive(): View
    {
        return view('api-docs');
    }
}
