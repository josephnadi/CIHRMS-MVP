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
