<?php

declare(strict_types=1);

use App\Enums\EnrolmentStatus;
use App\Enums\JobPostingStatus;
use App\Http\Controllers\MessagingController;
use App\Http\Requests\Recruitment\ApplyJobRequest;
use App\Models\Applicant;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\JobPosting;
use App\Models\StaffPhonePin;
use App\Models\User;
use App\Services\LearningService;
use App\Services\Messaging\Sms\SmsDispatcher;
use App\Services\RecruitmentService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Tier-4 transaction & race-condition regressions
|--------------------------------------------------------------------------
|
| Audit V2 — Tier 4 wrapped 4 multi-step write sequences in DB::transaction
| and closed 3 race conditions on shared state. Tests below assert the
| rollback semantics or single-conversation-per-pair invariant for the
| sites that are testable in a single-process feature test. The headcount
| ceiling race (PositionService) needs real concurrency to exercise so it
| isn't covered here — the row-lock is asserted by code review.
|
*/

// ─── T4.2 — MessagingController::issuePin ────────────────────────────────
//
// After the fix, the PIN updateOrCreate runs inside DB::transaction and
// the SMS dispatch happens *after* commit. If the SMS provider throws,
// the PIN must still be persisted so HR can resend without the rotation
// silently disappearing. The pre-fix order — updateOrCreate first, SMS
// second, no transaction — left the row in a state where the employee
// thought they had a working PIN but couldn't actually receive it.

it('rotates the PIN even when the SMS dispatcher throws after commit', function () {
    $hr  = User::factory()->create(['role' => 'hr_admin']);
    $emp = Employee::factory()->create();

    // Pre-seed an existing PIN so we can prove the row was rotated (not just created).
    StaffPhonePin::create([
        'employee_id'     => $emp->id,
        'phone'           => '+233200000000',
        'pin_hash'        => Hash::make('0000'),
        'failed_attempts' => 0,
    ]);

    // SMS dispatcher always throws — simulates an upstream provider outage.
    $this->mock(SmsDispatcher::class, function ($mock) {
        $mock->shouldReceive('send')->andThrow(new RuntimeException('SMS provider down'));
    });

    // Call the controller method directly to bypass the 2fa:fresh
    // middleware on the route — the fix is in the controller body, not
    // in the auth chain, so this is the cleanest way to exercise it.
    $controller = app(MessagingController::class);
    $request    = Request::create('/admin/messaging/pins', 'POST', [
        'employee_id'   => $emp->id,
        'phone'         => '+233244111222',
        'validity_days' => 30,
    ]);
    $request->setUserResolver(fn () => $hr);

    try {
        $controller->issuePin($request);
    } catch (Throwable) {
        // Expected — provider throws. The point is the PIN row should
        // still have been updated before the throw.
    }

    $pin = StaffPhonePin::where('employee_id', $emp->id)->first();
    expect($pin)->not->toBeNull();
    expect($pin->phone)->toBe('+233244111222');         // rotated
    expect(Hash::check('0000', $pin->pin_hash))->toBeFalse(); // rotated away from old PIN
});

// ─── T4.3 — LearningService::recordProgress ──────────────────────────────
//
// The progress update + conditional completeEnrolment call now share a
// transaction. If completeEnrolment throws (here: via an event listener
// that vetoes the completion), the progress bump must roll back so we
// don't leave an Enrolment at 100% with status still Active.

it('rolls back recordProgress when completion fails', function () {
    $emp    = Employee::factory()->create();
    $course = Course::create([
        'title'        => 'Compliance 101',
        'category'     => 'compliance',
        'format'       => 'self_paced',
        'is_published' => true,
        'published_at' => now(),
    ]);
    $enrolment = Enrolment::create([
        'course_id'    => $course->id,
        'employee_id'  => $emp->id,
        'status'       => EnrolmentStatus::Active->value,
        'progress_pct' => 50.0,
        'enrolled_at'  => now()->subDays(7),
        'started_at'   => now()->subDays(5),
    ]);

    // Hook a "save" event on Enrolment that vetoes any write that sets
    // status to Completed. This forces completeEnrolment() (inside
    // recordProgress) to throw, exercising the outer rollback.
    Enrolment::saving(function (Enrolment $e) {
        if ($e->status === EnrolmentStatus::Completed) {
            throw new RuntimeException('Synthetic completion veto');
        }
    });

    $svc = app(LearningService::class);

    try {
        $svc->recordProgress($enrolment, 100.0);
    } catch (Throwable) {
        // Expected.
    }

    $fresh = $enrolment->fresh();
    expect((float) $fresh->progress_pct)->toBe(50.0);             // rolled back
    expect($fresh->status)->toBe(EnrolmentStatus::Active);        // rolled back
});

// ─── T4.4 — RecruitmentService::apply ────────────────────────────────────
//
// CV is stored to disk first, then the applicant row is inserted inside
// a transaction. If the insert throws, the orphan file must be deleted.

it('deletes the orphan CV when the applicant insert fails', function () {
    Storage::fake('local');

    $job = JobPosting::factory()->create(['status' => JobPostingStatus::Open->value]);

    // Veto the Applicant insert via a creating-model hook.
    Applicant::creating(function () {
        throw new RuntimeException('Synthetic insert failure');
    });

    // Build a fake request that the service can consume directly.
    $file = UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf');
    $request = ApplyJobRequest::create('/recruitment/apply', 'POST', [
        'name'  => 'Akua Owusu',
        'email' => 'akua@example.test',
    ], [], ['cv' => $file]);
    $request->setContainer(app())->setRedirector(app(\Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    $svc = app(RecruitmentService::class);

    expect(fn () => $svc->apply($request, $job))
        ->toThrow(RuntimeException::class);

    // No applicant row was created…
    expect(Applicant::where('job_posting_id', $job->id)->count())->toBe(0);

    // …and no orphan file remains on disk.
    $allFiles = Storage::disk('local')->files('applicant-cvs');
    expect($allFiles)->toBeEmpty();
});

// ─── T4.7 — Conversation::findOrCreateOneOnOne ───────────────────────────
//
// Two back-to-back resolves for the same pair must return the same
// conversation row. The sharedLock + re-check inside the transaction
// guarantees the second call sees the first's commit rather than racing
// it into a second row.

it('returns the same 1:1 conversation across repeated resolves for a pair', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $first  = Conversation::findOrCreateOneOnOne($a, $b);
    $second = Conversation::findOrCreateOneOnOne($a, $b);
    $reverse = Conversation::findOrCreateOneOnOne($b, $a);

    expect($first->id)->toBe($second->id);
    expect($first->id)->toBe($reverse->id);

    // Exactly one conversation row exists for this pair.
    $count = Conversation::query()
        ->where('is_group', false)
        ->whereHas('participants', fn ($q) => $q->where('users.id', $a->id))
        ->whereHas('participants', fn ($q) => $q->where('users.id', $b->id))
        ->count();
    expect($count)->toBe(1);
});
