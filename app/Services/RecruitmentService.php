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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

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
        // Store the CV first, then attempt the DB insert inside a transaction.
        // If the insert (or any subsequent step inside the txn) throws, delete
        // the now-orphaned file so we don't leak storage on rollback.
        $cvPath = $request->hasFile('cv')
            ? $request->file('cv')->store('applicant-cvs', 'public')
            : null;

        try {
            return DB::transaction(function () use ($request, $jobPosting, $cvPath) {
                return $jobPosting->applicants()->create([
                    'name'    => $request->validated('name'),
                    'email'   => $request->validated('email'),
                    'cv_path' => $cvPath,
                    'status'  => ApplicantStatus::Applied,
                ]);
            });
        } catch (Throwable $e) {
            if ($cvPath) {
                Storage::disk('public')->delete($cvPath);
            }
            throw $e;
        }
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
