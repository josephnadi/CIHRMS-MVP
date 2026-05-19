<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->doc = Document::factory()->for($this->owner, 'owner')->create();
    $file = UploadedFile::fake()->create('memo.pdf', 50, 'application/pdf');
    Storage::disk('local')->putFileAs(sprintf('documents/%s/v1', $this->doc->uuid), $file, 'memo.pdf');
    $version = DocumentVersion::factory()->for($this->doc)->create([
        'original_name' => 'memo.pdf',
        'storage_path'  => sprintf('documents/%s/v1/memo.pdf', $this->doc->uuid),
        'mime'          => 'application/pdf',
    ]);
    $this->doc->update(['current_version_id' => $version->id]);
});

it('rejects downloads without a signature', function () {
    $this->actingAs($this->owner)
        ->get(route('documents.download', $this->doc->uuid))
        ->assertForbidden();
});

it('accepts downloads with a valid signed URL', function () {
    $signed = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), [
        'document' => $this->doc->uuid,
    ]);
    $this->actingAs($this->owner)
        ->get($signed)
        ->assertOk();
});

it('rejects downloads with an expired signed URL', function () {
    $signed = URL::temporarySignedRoute('documents.download', now()->subMinutes(5), [
        'document' => $this->doc->uuid,
    ]);
    $this->actingAs($this->owner)
        ->get($signed)
        ->assertForbidden();
});
