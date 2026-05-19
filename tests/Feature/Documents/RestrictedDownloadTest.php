<?php

use App\Enums\DocumentConfidentiality;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

it('forces watermarked PDF output for restricted documents', function () {
    Storage::fake('local');
    $owner = User::factory()->create();

    // Generate a real, spec-compliant 1-page PDF via TCPDF.
    // `UploadedFile::fake()->create(...)` produces a zero-byte file that FPDI
    // (which DocumentRenderService::burn uses) cannot parse, so we mint a
    // minimal genuine PDF here instead.
    $pdf = new \TCPDF('P', 'mm', 'A4');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'Test', 0, 1);
    $fixturePath = tempnam(sys_get_temp_dir(), 'rdt') . '.pdf';
    $pdf->Output($fixturePath, 'F');
    $pdfBytes = file_get_contents($fixturePath);
    @unlink($fixturePath);

    $doc = Document::factory()->for($owner, 'owner')->create([
        'confidentiality' => DocumentConfidentiality::Restricted,
    ]);
    Storage::disk('local')->put(
        sprintf('documents/%s/v1/memo.pdf', $doc->uuid),
        $pdfBytes,
    );
    $version = DocumentVersion::factory()->for($doc)->create([
        'original_name' => 'memo.pdf',
        'storage_path'  => sprintf('documents/%s/v1/memo.pdf', $doc->uuid),
        'mime'          => 'application/pdf',
    ]);
    $doc->update(['current_version_id' => $version->id]);

    $signed = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
        'document' => $doc->uuid,
    ]);

    $response = $this->actingAs($owner)->get($signed);

    // Don't crash, returns a PDF. The content-disposition filename should
    // signal restricted, never the raw original.
    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('-restricted.pdf');
});
