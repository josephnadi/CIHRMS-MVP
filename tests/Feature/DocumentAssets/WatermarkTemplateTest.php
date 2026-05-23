<?php

use App\Models\User;
use App\Models\WatermarkTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('user can create a text watermark', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('settings.watermarks.store'), [
            'name'        => 'Confidential',
            'owner_scope' => 'personal',
            'type'        => 'text',
            'text'        => 'CONFIDENTIAL',
            'color'       => '#dc2626',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('watermark_templates', [
        'name' => 'Confidential', 'type' => 'text', 'text' => 'CONFIDENTIAL',
    ]);
});

it('user can upload an image watermark', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->image('wm.png', 600, 600)->mimeType('image/png');

    $this->actingAs($user)
        ->post(route('settings.watermarks.store'), [
            'name'        => 'Logo WM',
            'owner_scope' => 'personal',
            'type'        => 'image',
            'file'        => $png,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('watermark_templates', [
        'name' => 'Logo WM', 'type' => 'image',
    ]);
});

it('rejects image watermark above 1 MB', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->create('wm.png', 1500, 'image/png');
    $this->actingAs($user)
        ->post(route('settings.watermarks.store'), [
            'name' => 'Big', 'owner_scope' => 'personal', 'type' => 'image', 'file' => $png,
        ])
        ->assertSessionHasErrors('file');
});

it('creator can delete their watermark', function () {
    $user = User::factory()->create();
    $tpl  = WatermarkTemplate::factory()->create(['created_by' => $user->id, 'owner_id' => $user->id]);

    $this->actingAs($user)->delete(route('settings.watermarks.destroy', $tpl->id))->assertRedirect();
    $this->assertDatabaseMissing('watermark_templates', ['id' => $tpl->id]);
});
