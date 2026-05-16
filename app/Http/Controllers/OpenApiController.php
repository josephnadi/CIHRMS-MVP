<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Serves the OpenAPI 3.0 spec at GET /api/v1/openapi.yaml. Public — the spec
 * itself contains no secrets, only the shape of the API surface.
 */
class OpenApiController extends Controller
{
    public function show(): HttpResponse
    {
        $path = storage_path('api/openapi.yaml');
        abort_unless(file_exists($path), 404, 'OpenAPI spec not found.');

        return response()->file($path, [
            'Content-Type'        => 'application/yaml; charset=UTF-8',
            'Cache-Control'       => 'public, max-age=300',
            'Content-Disposition' => 'inline; filename="cihrms-openapi-v1.yaml"',
        ]);
    }
}
