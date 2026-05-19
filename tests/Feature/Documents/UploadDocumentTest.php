<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('uploads a document', function () {
    $user = User::factory()->create([
        'role'        => 'employee',
        'permissions' => ['documents.create'],
    ]);

    $file = UploadedFile::fake()->create('memo.pdf', 200, 'application/pdf');

    $this->actingAs($user)
        ->post(route('documents.store'), [
            'title'       => 'Annual Memo',
            'description' => 'Year-end memo',
            'file'        => $file,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('documents', [
        'title'    => 'Annual Memo',
        'owner_id' => $user->id,
        'status'   => 'draft',
    ]);
    $this->assertDatabaseCount('document_versions', 1);
    $this->assertDatabaseHas('document_events', ['type' => 'uploaded']);
});

it('rejects oversized uploads', function () {
    $user = User::factory()->create([
        'role'        => 'employee',
        'permissions' => ['documents.create'],
    ]);

    $file = UploadedFile::fake()->create('big.pdf', 26000, 'application/pdf');

    $this->actingAs($user)
        ->post(route('documents.store'), ['title' => 'big', 'file' => $file])
        ->assertSessionHasErrors('file');
});
