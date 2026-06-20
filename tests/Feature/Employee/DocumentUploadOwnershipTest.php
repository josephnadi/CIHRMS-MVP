<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

it('rejects upload from an unrelated random user', function () {
    $owner = User::factory()->create();
    $emp   = Employee::factory()->create(['user_id' => $owner->id]);

    // Pin role to `employee` — the factory randomly picks from
    // ['employee','manager','hr_admin','finance_officer']; an `hr_admin`
    // roll grants `employees.manage` via legacy ROLE_PERMISSIONS and the
    // FormRequest authorize() would (correctly) allow the upload, flaking
    // this 1-in-4. Same fix applied to EmployeeSelfEditRestrictionTest.
    $randomUser = User::factory()->create(['role' => 'employee', 'permissions' => []]);

    $this->actingAs($randomUser)
        ->post(route('employees.documents.store', $emp), [
            'title'    => 'Fake contract',
            'document' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        ])
        ->assertForbidden();
});

it('allows the employee to upload to their own record', function () {
    $user = User::factory()->create(['permissions' => []]);
    $emp  = Employee::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('employees.documents.store', $emp), [
            'title'    => 'My CV',
            'document' => UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf'),
        ])
        ->assertRedirect();
});

it('allows an HR user with employees.manage to upload to any employee', function () {
    $hr  = User::factory()->create(['permissions' => ['employees.manage']]);
    $emp = Employee::factory()->create();

    $this->actingAs($hr)
        ->post(route('employees.documents.store', $emp), [
            'title'    => 'Onboarding docs',
            'document' => UploadedFile::fake()->create('onboard.pdf', 80, 'application/pdf'),
        ])
        ->assertRedirect();
});

it('lets an HR user delete an employee document and removes the file', function () {
    $hr  = User::factory()->create(['permissions' => ['employees.manage']]);
    $emp = Employee::factory()->create();

    $this->actingAs($hr)->post(route('employees.documents.store', $emp), [
        'title'    => 'Contract',
        'document' => UploadedFile::fake()->create('contract.pdf', 60, 'application/pdf'),
    ])->assertRedirect();

    $doc = $emp->documents()->firstOrFail();
    Storage::disk('local')->assertExists($doc->file_path);

    $this->actingAs($hr)->delete(route('employees.documents.destroy', [$emp, $doc]))->assertRedirect();

    expect(App\Models\EmployeeDocument::find($doc->id))->toBeNull();
    Storage::disk('local')->assertMissing($doc->file_path);
});

it('forbids an unrelated user from deleting an employee document', function () {
    $owner = User::factory()->create();
    $emp   = Employee::factory()->create(['user_id' => $owner->id]);
    $doc   = $emp->documents()->create(['title' => 'X', 'file_path' => 'employee-documents/x.pdf', 'mime_type' => 'application/pdf']);

    $this->actingAs(User::factory()->create(['role' => 'employee', 'permissions' => []]))
        ->delete(route('employees.documents.destroy', [$emp, $doc]))
        ->assertForbidden();

    expect(App\Models\EmployeeDocument::find($doc->id))->not->toBeNull();
});

it('404s when the document does not belong to the employee in the route', function () {
    $hr   = User::factory()->create(['permissions' => ['employees.manage']]);
    $empA = Employee::factory()->create();
    $empB = Employee::factory()->create();
    $doc  = $empB->documents()->create(['title' => 'B', 'file_path' => 'employee-documents/b.pdf', 'mime_type' => 'application/pdf']);

    $this->actingAs($hr)->delete(route('employees.documents.destroy', [$empA, $doc]))->assertNotFound();
});
