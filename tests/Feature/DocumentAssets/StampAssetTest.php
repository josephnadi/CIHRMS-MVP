<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\StampAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('user can upload a personal stamp', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->image('approved.png', 200, 60)->mimeType('image/png');

    $this->actingAs($user)
        ->post(route('settings.stamps.store'), [
            'name'        => 'Approved',
            'owner_scope' => 'personal',
            'file'        => $png,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('stamp_assets', [
        'name'        => 'Approved',
        'owner_scope' => 'personal',
        'owner_id'    => $user->id,
        'created_by'  => $user->id,
    ]);
});

it('rejects non-PNG file', function () {
    $user = User::factory()->create();
    $jpg  = UploadedFile::fake()->image('approved.jpg', 200, 60)->mimeType('image/jpeg');

    $this->actingAs($user)
        ->post(route('settings.stamps.store'), [
            'name' => 'Approved', 'owner_scope' => 'personal', 'file' => $jpg,
        ])
        ->assertSessionHasErrors('file');
});

it('rejects oversized PNG (>1 MB)', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->create('big.png', 1500, 'image/png');

    $this->actingAs($user)
        ->post(route('settings.stamps.store'), [
            'name' => 'Big', 'owner_scope' => 'personal', 'file' => $png,
        ])
        ->assertSessionHasErrors('file');
});

it('blocks org-scope upload without document_assets.manage', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->image('a.png', 200, 60)->mimeType('image/png');

    $this->actingAs($user)
        ->post(route('settings.stamps.store'), [
            'name' => 'Org', 'owner_scope' => 'organization', 'file' => $png,
        ])
        ->assertForbidden();
});

it('allows org-scope upload with document_assets.manage', function () {
    $user = User::factory()->create(['permissions' => ['document_assets.manage']]);
    $png  = UploadedFile::fake()->image('a.png', 200, 60)->mimeType('image/png');

    $this->actingAs($user)
        ->post(route('settings.stamps.store'), [
            'name' => 'Org', 'owner_scope' => 'organization', 'file' => $png,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('stamp_assets', [
        'name' => 'Org', 'owner_scope' => 'organization',
    ]);
});

it('allows department-scope upload only by a member of that department', function () {
    $dept = Department::factory()->create();
    $member = User::factory()->create();
    Employee::factory()->create(['user_id' => $member->id, 'department_id' => $dept->id]);
    $outsider = User::factory()->create();
    $png = UploadedFile::fake()->image('d.png', 200, 60)->mimeType('image/png');

    $this->actingAs($outsider)
        ->post(route('settings.stamps.store'), [
            'name' => 'Dept', 'owner_scope' => 'department', 'owner_id' => $dept->id, 'file' => $png,
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('settings.stamps.store'), [
            'name' => 'Dept', 'owner_scope' => 'department', 'owner_id' => $dept->id, 'file' => $png,
        ])
        ->assertRedirect();
});

it('creator can delete their stamp', function () {
    $user  = User::factory()->create();
    $asset = StampAsset::factory()->create(['created_by' => $user->id, 'owner_id' => $user->id]);

    $this->actingAs($user)->delete(route('settings.stamps.destroy', $asset->id))->assertRedirect();
    $this->assertDatabaseMissing('stamp_assets', ['id' => $asset->id]);
});

it('non-creator without manage cannot delete', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $asset = StampAsset::factory()->create(['created_by' => $owner->id, 'owner_id' => $owner->id]);

    $this->actingAs($other)->delete(route('settings.stamps.destroy', $asset->id))->assertForbidden();
});
