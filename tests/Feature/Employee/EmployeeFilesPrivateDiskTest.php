<?php

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

it('uploads avatars to the private local disk (not public)', function () {
    $user = User::factory()->create(['permissions' => []]);
    $emp  = Employee::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post(route('employees.avatar.store', $emp), [
        'avatar' => UploadedFile::fake()->image('me.png'),
    ])->assertRedirect();

    $emp->refresh();
    expect($emp->avatar_path)->not->toBeNull();
    Storage::disk('local')->assertExists($emp->avatar_path);
    Storage::disk('public')->assertMissing($emp->avatar_path);
});

it('uploads employee documents to the private local disk (not public)', function () {
    $user = User::factory()->create(['permissions' => []]);
    $emp  = Employee::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post(route('employees.documents.store', $emp), [
        'title'    => 'CV',
        'document' => UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf'),
    ])->assertRedirect();

    $doc = EmployeeDocument::latest('id')->first();
    Storage::disk('local')->assertExists($doc->file_path);
    Storage::disk('public')->assertMissing($doc->file_path);
});

it('rejects unsigned access to the avatar endpoint', function () {
    $owner = User::factory()->create();
    $emp   = Employee::factory()->create(['user_id' => $owner->id, 'avatar_path' => 'avatars/test.png']);

    $this->actingAs($owner)
        ->get(route('employees.files.avatar', ['employee' => $emp->id]))  // unsigned
        ->assertStatus(403);
});

it('serves the avatar when the link is signed and the caller can view the employee', function () {
    Storage::disk('local')->put('avatars/me.png', 'fake-bytes');
    $owner = User::factory()->create();
    $emp   = Employee::factory()->create(['user_id' => $owner->id, 'avatar_path' => 'avatars/me.png']);

    $url = URL::temporarySignedRoute(
        'employees.files.avatar',
        now()->addMinutes(15),
        ['employee' => $emp->id],
    );

    $this->actingAs($owner)->get($url)->assertOk();
});

it('avatar_url accessor returns a signed URL pointing at the streaming endpoint', function () {
    $user = User::factory()->create();
    $emp  = Employee::factory()->create([
        'user_id'     => $user->id,
        'avatar_path' => 'avatars/foo.png',
    ]);

    $this->actingAs($user);
    $url = $emp->avatar_url;
    expect($url)->toContain('/employees/' . $emp->id . '/files/avatar');
    expect($url)->toContain('signature=');
});
