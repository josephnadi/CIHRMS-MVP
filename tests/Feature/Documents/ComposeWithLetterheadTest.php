<?php

use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

// 1x1 transparent PNG so TCPDF's image() call inside SetHeaderData succeeds.
// "fake-png-content" would crash the renderer with a libpng signature error,
// taking down the controller redirect path and the surrounding assertion.
$validPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

beforeEach(fn () => Storage::fake('local'));

it('compose attaches the selected letterhead_id', function () use ($validPng) {
    Storage::disk('local')->put('assets/letterheads/test.png', $validPng);
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $tpl   = LetterheadTemplate::factory()->create([
        'owner_scope'  => 'personal',
        'owner_id'     => $owner->id,
        'created_by'   => $owner->id,
        'storage_path' => 'assets/letterheads/test.png',
        'mime'         => 'image/png',
    ]);

    $this->actingAs($owner)
        ->post(route('documents.compose.store'), [
            'title'           => 'Letter',
            'confidentiality' => 'internal',
            'letterhead_id'   => $tpl->id,
            'body_html'       => '<p>Hello</p>',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('documents', ['title' => 'Letter', 'letterhead_id' => $tpl->id]);
});

it('compose falls back to the default letterhead when none selected', function () use ($validPng) {
    Storage::disk('local')->put('assets/letterheads/default.png', $validPng);
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $default = LetterheadTemplate::factory()->create([
        'owner_scope'  => 'organization',
        'owner_id'     => null,
        'is_default'   => true,
        'storage_path' => 'assets/letterheads/default.png',
        'created_by'   => $owner->id,
    ]);

    $this->actingAs($owner)
        ->post(route('documents.compose.store'), [
            'title'           => 'Auto',
            'confidentiality' => 'internal',
            'body_html'       => '<p>Hello</p>',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('documents', ['title' => 'Auto', 'letterhead_id' => $default->id]);
});
