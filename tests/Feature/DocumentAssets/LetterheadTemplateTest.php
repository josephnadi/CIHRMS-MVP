<?php

use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('user can upload a personal letterhead', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->image('lh.png', 1200, 200);

    $this->actingAs($user)
        ->post(route('settings.letterheads.store'), [
            'name' => 'My LH', 'owner_scope' => 'personal', 'file' => $png,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('letterhead_templates', ['name' => 'My LH', 'owner_scope' => 'personal']);
});

it('cannot delete the seeded default letterhead', function () {
    $user = User::factory()->create(['permissions' => ['document_assets.manage']]);
    $tpl  = LetterheadTemplate::factory()->create([
        'is_default' => true, 'owner_scope' => 'organization', 'owner_id' => null,
    ]);
    $this->actingAs($user)
        ->delete(route('settings.letterheads.destroy', $tpl->id))
        ->assertForbidden();
});

it('org-scope upload requires document_assets.manage', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->image('lh.png', 1200, 200);
    $this->actingAs($user)
        ->post(route('settings.letterheads.store'), [
            'name' => 'Org', 'owner_scope' => 'organization', 'file' => $png,
        ])
        ->assertForbidden();
});
