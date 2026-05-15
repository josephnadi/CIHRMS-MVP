<?php

namespace App\Http\Controllers;

use App\Services\Reports\AuditorGeneralReportPack;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuditorGeneralReportController extends Controller
{
    public function __construct(private readonly AuditorGeneralReportPack $pack) {}

    public function index(Request $request): Response
    {
        abort_unless(
            $request->user()->hasPermission('reports.view') || $request->user()->hasPermission('audit.view'),
            403,
        );

        // List existing packs in storage/app/ag-reports/ so the auditor can
        // re-download a previously generated bundle without re-running the
        // (relatively expensive) generation.
        $disk = \Storage::disk('local');
        $existing = [];
        foreach ($disk->files('ag-reports') as $file) {
            if (! str_ends_with($file, '.zip')) continue;
            $existing[] = [
                'name'     => basename($file),
                'path'     => $file,
                'size'     => $disk->size($file),
                'created'  => $disk->lastModified($file),
            ];
        }
        usort($existing, fn ($a, $b) => $b['created'] - $a['created']);

        return Inertia::render('Reports/AuditorGeneral', [
            'existing'     => $existing,
            'current_year' => now()->year,
            'activeModule' => 'reports',
        ]);
    }

    public function generate(Request $request)
    {
        abort_unless($request->user()->hasPermission('statutory.export'), 403);

        $data = $request->validate([
            'year'         => ['required', 'integer', 'min:2000', 'max:2100'],
            'jurisdiction' => ['nullable', 'string', 'in:GH'],
        ]);

        $path = $this->pack->generate(
            fiscalYear:   (int) $data['year'],
            jurisdiction: $data['jurisdiction'] ?? 'GH',
        );

        return back()->with('success', 'AG pack generated: ' . basename($path));
    }

    public function download(Request $request, string $filename): BinaryFileResponse
    {
        abort_unless($request->user()->hasPermission('statutory.export'), 403);

        // Defensive: prevent path traversal — only allow exact basenames inside ag-reports.
        if (! preg_match('/^AG-\d{4}-\d{8}-\d{6}\.zip$/', $filename)) {
            abort(404);
        }

        $disk = \Storage::disk('local');
        $path = "ag-reports/{$filename}";
        abort_unless($disk->exists($path), 404);

        return $disk->download($path);
    }
}
