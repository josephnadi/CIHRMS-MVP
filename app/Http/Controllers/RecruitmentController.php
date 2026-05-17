<?php

namespace App\Http\Controllers;

use App\Events\OfferEnvelopeRequested;
use App\Http\Requests\Recruitment\ApplyJobRequest;
use App\Http\Requests\Recruitment\StoreJobPostingRequest;
use App\Http\Requests\Recruitment\UpdateApplicantStatusRequest;
use App\Http\Resources\ApplicantResource;
use App\Http\Resources\JobPostingResource;
use App\Integrations\IntegrationManager;
use App\Models\Applicant;
use App\Models\JobPosting;
use App\Services\RecruitmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecruitmentController extends Controller
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    public function index(): Response
    {
        return Inertia::render('Recruitment/Index', [
            'jobs'         => JobPostingResource::collection($this->recruitment->listPostings()),
            'activeModule' => 'recruitment',
        ]);
    }

    public function show(JobPosting $job): Response
    {
        return Inertia::render('Recruitment/Show', [
            'job'          => new JobPostingResource($job->load('applicants')),
            'activeModule' => 'recruitment',
        ]);
    }

    public function applicants(JobPosting $job): Response
    {
        return Inertia::render('Recruitment/Applicants', [
            'job'          => new JobPostingResource($job),
            'applicants'   => ApplicantResource::collection($this->recruitment->listApplicants($job)),
            'activeModule' => 'recruitment',
        ]);
    }

    public function updateApplicant(UpdateApplicantStatusRequest $request, Applicant $applicant): RedirectResponse
    {
        $this->recruitment->updateApplicant($request, $applicant);

        return back()->with('success', 'Applicant status updated.');
    }

    public function showPublic(JobPosting $job): Response
    {
        return Inertia::render('Careers/Show', [
            'job' => new JobPostingResource($job),
        ]);
    }

    public function createJob(StoreJobPostingRequest $request): RedirectResponse
    {
        $this->recruitment->createPosting($request);

        return back()->with('success', 'Job posting created successfully.');
    }

    public function apply(ApplyJobRequest $request, JobPosting $job): RedirectResponse
    {
        $this->recruitment->apply($request, $job);

        return back()->with('success', 'Application submitted successfully.');
    }

    /**
     * Render an offer-letter PDF for the given applicant and dispatch it to
     * the configured e-sign provider (Zoho Sign / DocuSign) via OfferEnvelopeRequested.
     */
    public function sendOffer(Request $request, Applicant $applicant, IntegrationManager $integrations): RedirectResponse
    {
        if (! $integrations->isAvailable('esign')) {
            return back()->with('error', 'No e-sign provider is connected — open Admin → Integrations to set one up.');
        }

        $validated = $request->validate([
            'salary'      => ['nullable', 'numeric', 'min:0'],
            'start_date'  => ['nullable', 'date'],
            'expires_in'  => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $applicant->loadMissing('jobPosting');

        $pdf = Pdf::loadView('pdf.offer-letter', [
            'applicant'  => $applicant,
            'job'        => $applicant->jobPosting,
            'salary'     => $validated['salary'] ?? null,
            'startDate'  => $validated['start_date'] ?? null,
            'expiresIn'  => $validated['expires_in'] ?? 14,
            'sentBy'     => $request->user()?->name,
        ])->output();

        OfferEnvelopeRequested::dispatch(
            applicant:    $applicant,
            pdfBase64:    base64_encode($pdf),
            documentName: 'Offer-Letter-'.preg_replace('/[^A-Za-z0-9]+/', '-', $applicant->name).'.pdf',
            subject:      "Offer of Employment — {$applicant->jobPosting?->title}",
            message:      "Hi {$applicant->name},\n\nPlease review and sign the attached offer letter. Reply to this email if you have questions.\n\nWith warm regards,\nCIHRM Ghana",
            actor:        $request->user(),
        );

        return back()->with('success', 'Offer letter is being sent for signature.');
    }
}
