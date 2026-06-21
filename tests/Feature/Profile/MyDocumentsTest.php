<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user     = User::factory()->create(['role' => 'employee']);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
});

function ownUpload(Employee $employee, User $by, string $title = 'My File'): EmployeeDocument
{
    Storage::disk('local')->put("employee-documents/{$title}.pdf", 'BYTES');

    return $employee->documents()->create([
        'uploaded_by' => $by->id, 'title' => $title,
        'file_path' => "employee-documents/{$title}.pdf", 'mime_type' => 'application/pdf',
    ]);
}

it('lets an employee upload a document to their own file (C)', function () {
    $this->actingAs($this->user)
        ->post(route('profile.documents.store'), [
            'title'    => 'Bank statement',
            'document' => UploadedFile::fake()->create('statement.pdf', 50, 'application/pdf'),
        ])
        ->assertRedirect();

    $doc = EmployeeDocument::firstOrFail();
    expect($doc->employee_id)->toBe($this->employee->id)
        ->and($doc->uploaded_by)->toBe($this->user->id)
        ->and($doc->title)->toBe('Bank statement');
    Storage::disk('local')->assertExists($doc->file_path);
});

it('lets an employee download their own document (R)', function () {
    $doc = ownUpload($this->employee, $this->user);

    $this->actingAs($this->user)
        ->get(route('profile.documents.download', $doc))
        ->assertOk()
        ->assertDownload();
});

it('lets an employee rename and replace their own upload (U)', function () {
    $doc = ownUpload($this->employee, $this->user, 'Old');
    $oldPath = $doc->file_path;

    $this->actingAs($this->user)
        ->post(route('profile.documents.update', $doc), [
            'title'    => 'Renamed',
            'document' => UploadedFile::fake()->create('new.pdf', 20, 'application/pdf'),
        ])
        ->assertRedirect();

    $doc->refresh();
    expect($doc->title)->toBe('Renamed')
        ->and($doc->file_path)->not->toBe($oldPath);
    Storage::disk('local')->assertMissing($oldPath);     // old file removed
    Storage::disk('local')->assertExists($doc->file_path);
});

it('lets an employee delete their own upload (D)', function () {
    $doc = ownUpload($this->employee, $this->user);
    $path = $doc->file_path;

    $this->actingAs($this->user)
        ->delete(route('profile.documents.destroy', $doc))
        ->assertRedirect();

    expect(EmployeeDocument::find($doc->id))->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('forbids editing or deleting an HR-uploaded document (download-only)', function () {
    $hr  = User::factory()->create(['role' => 'hr_admin']);
    $doc = ownUpload($this->employee, $hr, 'HR Contract'); // on my file, but uploaded by HR

    // Can still download it…
    $this->actingAs($this->user)->get(route('profile.documents.download', $doc))->assertOk();

    // …but cannot rename/replace or delete it.
    $this->actingAs($this->user)
        ->post(route('profile.documents.update', $doc), ['title' => 'Hacked'])
        ->assertForbidden();
    $this->actingAs($this->user)
        ->delete(route('profile.documents.destroy', $doc))
        ->assertForbidden();

    expect($doc->fresh()->title)->toBe('HR Contract');
});

it('prevents reaching another employee\'s document (IDOR)', function () {
    $otherUser = User::factory()->create(['role' => 'employee']);
    $otherEmp  = Employee::factory()->create(['user_id' => $otherUser->id]);
    $theirDoc  = ownUpload($otherEmp, $otherUser, 'Private');

    $this->actingAs($this->user)
        ->get(route('profile.documents.download', $theirDoc))
        ->assertNotFound();
    $this->actingAs($this->user)
        ->delete(route('profile.documents.destroy', $theirDoc))
        ->assertForbidden();

    expect(EmployeeDocument::find($theirDoc->id))->not->toBeNull();
});
