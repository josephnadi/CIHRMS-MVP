<?php

use App\Enums\ApplicantStatus;
use App\Enums\JobPostingStatus;
use App\Models\Applicant;
use App\Models\JobPosting;
use App\Models\User;

beforeEach(function () {
    $this->hr = User::factory()->create(['role' => 'hr_admin']);
});

test('HR can post a new job', function () {
    $this->actingAs($this->hr)
        ->post(route('jobs.store'), [
            'title'       => 'Senior Backend Engineer',
            'description' => 'Build internal HR services with Laravel.',
            'closes_at'   => now()->addMonth()->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('job_postings', [
        'title'  => 'Senior Backend Engineer',
        'status' => JobPostingStatus::Open->value,
    ]);
});

test('public visitor can view an open job and apply', function () {
    $job = JobPosting::factory()->create([
        'status'    => JobPostingStatus::Open->value,
        'closes_at' => now()->addMonth(),
    ]);

    // Public view
    $this->get(route('careers.show', $job))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Careers/Show'));

    // Public apply (no auth)
    $this->post(route('careers.apply', $job), [
        'name'  => 'Ama Asante',
        'email' => 'ama@example.com',
    ])->assertRedirect();

    $this->assertDatabaseHas('applicants', [
        'name'           => 'Ama Asante',
        'email'          => 'ama@example.com',
        'job_posting_id' => $job->id,
        'status'         => ApplicantStatus::Applied->value,
    ]);
});

test('HR can move an applicant through the pipeline', function () {
    $job = JobPosting::factory()->create();
    $applicant = Applicant::factory()->create(['job_posting_id' => $job->id]);

    $this->actingAs($this->hr)
        ->patch(route('applicants.update', $applicant), [
            'status' => ApplicantStatus::Interviewed->value,
        ])
        ->assertRedirect();

    $applicant->refresh();
    expect($applicant->status->value)->toBe(ApplicantStatus::Interviewed->value);
});

test('recruitment index returns paginated postings with applicant counts', function () {
    JobPosting::factory()->count(3)->create();

    $this->actingAs($this->hr)
        ->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Recruitment/Index')
            ->has('jobs')
        );
});
