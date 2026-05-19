<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

it('writes a Downloaded event when a document is downloaded', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $doc = Document::factory()->for($owner, 'owner')->create();

    $file = UploadedFile::fake()->create('memo.pdf', 50, 'application/pdf');
    Storage::disk('local')->putFileAs(sprintf('documents/%s/v1', $doc->uuid), $file, 'memo.pdf');

    $version = DocumentVersion::factory()->for($doc)->create([
        'original_name' => 'memo.pdf',
        'storage_path'  => sprintf('documents/%s/v1/memo.pdf', $doc->uuid),
        'mime'          => 'application/pdf',
    ]);
    $doc->update(['current_version_id' => $version->id]);

    $signedUrl = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
        'document' => $doc->uuid,
    ]);

    $this->actingAs($owner)
        ->get($signedUrl)
        ->assertOk();

    $this->assertDatabaseHas('document_events', [
        'document_id' => $doc->id,
        'actor_id'    => $owner->id,
        'type'        => 'downloaded',
    ]);
});
