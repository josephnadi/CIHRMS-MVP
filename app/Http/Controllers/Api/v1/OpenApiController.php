<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

class OpenApiController extends Controller
{
    private const SPEC_PATH = 'api/openapi.v1.yaml';

    /** Raw YAML — preferred by tools (Postman import, Stoplight, etc.) */
    public function yaml(): Response
    {
        $path = storage_path(self::SPEC_PATH);
        abort_unless(file_exists($path), 404);

        return response(file_get_contents($path), 200, [
            'Content-Type'        => 'application/yaml; charset=utf-8',
            'Cache-Control'       => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** Convenience JSON form for browsers that don't render YAML inline. */
    public function json(): \Illuminate\Http\JsonResponse
    {
        $path = storage_path(self::SPEC_PATH);
        abort_unless(file_exists($path), 404);

        $array = \App\Support\MiniYaml::parseFile($path);
        return response()->json($array)
            ->withHeaders(['Access-Control-Allow-Origin' => '*']);
    }
}
