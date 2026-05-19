<?php

/**
 * End-to-end integration smoke test for the Documents module.
 *
 * Walks the full happy path at the HTTP layer — the closest substitute for a
 * browser smoke test we can run from CI without Dusk. Every public endpoint
 * the manual browser flow would hit is exercised in sequence by 3 distinct
 * users (owner + 2 recipients) so that authorization, state-machine
 * transitions, audit events, annotation persistence, signed-URL downloads,
 * and the restricted-watermark policy are all covered in one cohesive run.
 *
 * The accompanying manual checklist in `docs/QA_DOCUMENTS_SMOKE.md` covers
 * the things this test can't: visual rendering, signature_pad canvas drag,
 * stamp placement on a PDF page, and the actual look of the burned-PDF
 * watermark.
 */

use App\Enums\DocumentConfidentiality;
use App\Enums\DocumentEventType;
use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/** Produce a real 1-page PDF on disk so FPDI can parse it during burn. */
function pdfFixture(string $label = 'E2E fixture'): \Illuminate\Http\UploadedFile
{
    $pdf = new \TCPDF('P', 'mm', 'A4');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, $label, 0, 1);
    $tmp = tempnam(sys_get_temp_dir(), 'e2e') . '.pdf';
    $pdf->Output($tmp, 'F');

    return new \Illuminate\Http\UploadedFile(
        $tmp,
        'memo.pdf',
        'application/pdf',
        null,
        true,
    );
}

it('walks the full documents flow end-to-end (upload → annotate → route → act → download)', function () {
    Storage::fake('local');

    // ── Cast ───────────────────────────────────────────────────────────
    $owner = User::factory()->create([
        'name' => 'Adwoa Mensah',
        'role' => 'hr_admin',
        'permissions' => ['documents.create'],
    ]);
    $registrar = User::factory()->create([
        'name' => 'Kwame Owusu',
        'role' => 'manager',
        'permissions' => ['documents.view'],
    ]);
    $dhr = User::factory()->create([
        'name' => 'Esi Boateng',
        'role' => 'manager',
        'permissions' => ['documents.view'],
    ]);

    // ── 1. Owner uploads a real PDF ────────────────────────────────────
    $this->actingAs($owner)
        ->post(route('documents.store'), [
            'title'           => 'FY26 Performance Memo',
            'description'    => 'Routing through Registrar then DHR for sign-off.',
            'confidentiality' => 'internal',
            'file'            => pdfFixture('FY26 Performance Memo'),
        ])
        ->assertRedirect();

    $doc = Document::query()->where('owner_id', $owner->id)->latest('id')->firstOrFail();
    expect($doc->status)->toBe(DocumentStatus::Draft);
    expect($doc->ref_no)->toMatch('/^CIHRMS\\/DOC\\/\\d{4}\\/\\d{4}$/');
    expect($doc->currentVersion?->original_name)->toBe('memo.pdf');
    expect($doc->currentVersion?->sha256)->not->toBeEmpty();

    $this->assertDatabaseHas('document_events', [
        'document_id' => $doc->id,
        'actor_id'    => $owner->id,
        'type'        => DocumentEventType::Uploaded->value,
    ]);

    // ── 2. Owner annotates the draft (signature + stamp) ───────────────
    $this->actingAs($owner)
        ->post(route('documents.annotations.store', $doc->uuid), [
            'type' => 'signature',
            'page' => 1, 'x_pct' => 10, 'y_pct' => 80, 'w_pct' => 22, 'h_pct' => 8,
            'data' => [
                'png_base64' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            ],
        ])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('documents.annotations.store', $doc->uuid), [
            'type' => 'stamp',
            'page' => 1, 'x_pct' => 65, 'y_pct' => 12, 'w_pct' => 18, 'h_pct' => 6,
            'data' => ['text' => 'APPROVED', 'color' => '#059669'],
        ])
        ->assertRedirect();

    expect($doc->annotations()->count())->toBe(2);
    expect($doc->annotations()->where('type', 'signature')->exists())->toBeTrue();
    expect($doc->annotations()->where('type', 'stamp')->exists())->toBeTrue();

    // ── 3. Owner routes to Registrar → DHR ─────────────────────────────
    $this->actingAs($owner)
        ->post(route('documents.route', $doc->uuid), [
            'recipients' => [
                ['user_id' => $registrar->id, 'action_required' => 'sign'],
                ['user_id' => $dhr->id,       'action_required' => 'approve'],
            ],
        ])
        ->assertRedirect();

    $doc->refresh();
    expect($doc->status)->toBe(DocumentStatus::InReview);

    $routes = $doc->routes()->orderBy('sequence')->get();
    expect($routes)->toHaveCount(2);
    expect($routes[0]->to_user_id)->toBe($registrar->id);
    expect($routes[0]->status)->toBe(DocumentRouteStatus::InProgress);
    expect($routes[0]->from_user_id)->toBe($owner->id);
    expect($routes[1]->to_user_id)->toBe($dhr->id);
    expect($routes[1]->status)->toBe(DocumentRouteStatus::Pending);
    expect($routes[1]->from_user_id)->toBe($registrar->id);

    // ── 4. Registrar (route 1) adds their own annotation and acts ──────
    $this->actingAs($registrar)
        ->post(route('documents.annotations.store', $doc->uuid), [
            'type' => 'signature',
            'page' => 1, 'x_pct' => 35, 'y_pct' => 80, 'w_pct' => 22, 'h_pct' => 8,
            'data' => [
                'png_base64' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            ],
        ])
        ->assertRedirect();

    $this->actingAs($registrar)
        ->post(route('documents.routes.act', ['document' => $doc->uuid, 'route' => $routes[0]->id]), [
            'decision' => 'complete',
            'comment'  => 'Looks good — passing to DHR.',
        ])
        ->assertRedirect();

    $routes = $doc->routes()->orderBy('sequence')->get();
    expect($routes[0]->status)->toBe(DocumentRouteStatus::Completed);
    expect($routes[0]->comment)->toBe('Looks good — passing to DHR.');
    expect($routes[1]->status)->toBe(DocumentRouteStatus::InProgress);
    expect($doc->fresh()->status)->toBe(DocumentStatus::InReview);

    // ── 5. DHR (route 2) acts complete → document closes ───────────────
    $this->actingAs($dhr)
        ->post(route('documents.routes.act', ['document' => $doc->uuid, 'route' => $routes[1]->id]), [
            'decision' => 'complete',
            'comment'  => 'Approved.',
        ])
        ->assertRedirect();

    $doc->refresh();
    expect($doc->status)->toBe(DocumentStatus::Completed);
    expect($doc->routes()->where('sequence', 2)->first()->status)->toBe(DocumentRouteStatus::Completed);

    // ── 6. Timeline reflects the journey in the right order ────────────
    $eventTypes = $doc->events()->orderBy('occurred_at')->pluck('type')->map(fn ($e) => $e->value)->all();
    expect($eventTypes)->toContain(DocumentEventType::Uploaded->value);
    expect($eventTypes)->toContain(DocumentEventType::Routed->value);
    expect($eventTypes)->toContain(DocumentEventType::Forwarded->value);
    expect($eventTypes)->toContain(DocumentEventType::Completed->value);
    // Signed/Stamped events come from annotations; one of each at least.
    expect(in_array(DocumentEventType::Signed->value, $eventTypes, true))->toBeTrue();
    expect(in_array(DocumentEventType::Stamped->value, $eventTypes, true))->toBeTrue();

    // ── 7. Owner downloads the burned PDF via a signed URL ─────────────
    $signedBurned = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
        'document' => $doc->uuid,
        'burned'   => 1,
    ]);
    $response = $this->actingAs($owner)->get($signedBurned);
    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('-burned.pdf');

    // ── 8. Unsigned URL → 403 (F-2) ────────────────────────────────────
    $this->actingAs($owner)
        ->get(route('documents.download', $doc->uuid))
        ->assertForbidden();

    // ── 9. Switch to restricted → watermark policy kicks in (F-1) ──────
    $doc->update(['confidentiality' => DocumentConfidentiality::Restricted]);

    $signedRestricted = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
        'document' => $doc->uuid,
    ]);
    $restrictedResp = $this->actingAs($owner)->get($signedRestricted);
    $restrictedResp->assertOk();
    expect($restrictedResp->headers->get('Content-Disposition'))->toContain('-restricted.pdf');

    // F-3: each download writes a Downloaded event row.
    $downloadEvents = $doc->events()
        ->where('type', DocumentEventType::Downloaded->value)
        ->get();
    expect($downloadEvents->count())->toBeGreaterThanOrEqual(2);
    // The restricted download must have watermarked=true in its payload.
    $hasWatermarked = $downloadEvents->contains(fn ($e) => ($e->payload['watermarked'] ?? false) === true);
    expect($hasWatermarked)->toBeTrue();
});

it('blocks a non-recipient from acting on someone else\'s route', function () {
    Storage::fake('local');
    $owner    = User::factory()->create(['permissions' => ['documents.create']]);
    $intended = User::factory()->create(['permissions' => ['documents.view']]);
    $imposter = User::factory()->create(['permissions' => ['documents.view']]);

    $this->actingAs($owner)->post(route('documents.store'), [
        'title' => 'Sensitive', 'file' => pdfFixture('Sensitive'),
    ])->assertRedirect();
    $doc = Document::query()->where('owner_id', $owner->id)->latest('id')->firstOrFail();

    $this->actingAs($owner)->post(route('documents.route', $doc->uuid), [
        'recipients' => [['user_id' => $intended->id, 'action_required' => 'sign']],
    ])->assertRedirect();

    $route = $doc->routes()->first();

    $this->actingAs($imposter)
        ->post(route('documents.routes.act', ['document' => $doc->uuid, 'route' => $route->id]), [
            'decision' => 'complete',
        ])
        ->assertForbidden();

    // Still in_progress for the rightful recipient.
    expect($route->fresh()->status)->toBe(DocumentRouteStatus::InProgress);
});

it('rejects oversize and wrong-mime uploads at the FormRequest layer', function () {
    Storage::fake('local');
    $owner = User::factory()->create(['permissions' => ['documents.create']]);

    // Oversize (>25 MB).
    $this->actingAs($owner)
        ->post(route('documents.store'), [
            'title' => 'Big',
            'file'  => \Illuminate\Http\UploadedFile::fake()->create('big.pdf', 30_000, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');

    // Wrong mime (only pdf, docx, doc, png, jpg, jpeg accepted).
    $this->actingAs($owner)
        ->post(route('documents.store'), [
            'title' => 'Spreadsheet',
            'file'  => \Illuminate\Http\UploadedFile::fake()->create('numbers.xlsx', 50,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])
        ->assertSessionHasErrors('file');
});
