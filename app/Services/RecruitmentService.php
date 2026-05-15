<?php

namespace App\Services;

use App\Enums\ApplicantStatus;
use App\Enums\JobPostingStatus;
use App\Http\Requests\Recruitment\ApplyJobRequest;
use App\Http\Requests\Recruitment\StoreJobPostingRequest;
use App\Http\Requests\Recruitment\UpdateApplicantStatusRequest;
use App\Models\Applicant;
use App\Models\JobPosting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class RecruitmentService
{
    public function createPosting(StoreJobPostingRequest $request): JobPosting
    {
        return JobPosting::create([
            ...$request->validated(),
            'status' => JobPostingStatus::Open,
        ]);
    }

    public function apply(ApplyJobRequest $request, JobPosting $jobPosting): Applicant
    {
        return $jobPosting->applicants()->create([
            'name'    => $request->validated('name'),
            'email'   => $request->validated('email'),
            'cv_path' => $request->hasFile('cv')
                ? $request->file('cv')->store('applicant-cvs', 'public')
                : null,
            'status'  => ApplicantStatus::Applied,
        ]);
    }

    public function listPostings(bool $openOnly = false): Collection
    {
        return JobPosting::withCount('applicants')
            ->when($openOnly, fn ($q) => $q->open())
            ->latest()
            ->get();
    }

    public function listApplicants(JobPosting $job): LengthAwarePaginator
    {
        return $job->applicants()->latest()->paginate(20);
    }

    public function updateApplicant(UpdateApplicantStatusRequest $request, Applicant $applicant): Applicant
    {
        $applicant->update(['status' => ApplicantStatus::from($request->validated('status'))]);

        return $applicant->fresh('jobPosting');
    }
}
