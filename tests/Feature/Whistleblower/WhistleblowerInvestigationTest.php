<?php

use App\Enums\InvestigationActionType;
use App\Enums\WhistleblowerSeverity;
use App\Enums\WhistleblowerStatus;
use App\Models\User;
use App\Models\WhistleblowerReport;
use App\Services\Whistleblower\WhistleblowerInvestigationService;
use App\Services\Whistleblower\WhistleblowerSubmissionService;

beforeEach(function () {
    $this->submit = app(WhistleblowerSubmissionService::class);
    $this->svc    = app(WhistleblowerInvestigationService::class);
    $this->investigator = User::factory()->create(['role' => 'auditor']);

    $intake = $this->submit->submit([
        'category'        => 'corruption',
        'subject_summary' => 'Test case',
        'description'     => 'A test description long enough to pass validation.',
        'is_anonymous'    => true,
    ]);

    $this->report = $intake['report'];
    $this->tracking = $intake['tracking_code'];
});

it('triages a submitted report — sets severity, status, assigned investigator', function () {
    $r = $this->svc->triage($this->report, $this->investigator, WhistleblowerSeverity::High);

    expect($r->status)->toBe(WhistleblowerStatus::Triaged);
    expect($r->severity)->toBe(WhistleblowerSeverity::High);
    expect($r->assigned_investigator_id)->toBe($this->investigator->id);
    expect($r->triaged_by)->toBe($this->investigator->id);
    expect($r->actions()->where('action_type', InvestigationActionType::StatusChange->value)->count())->toBe(1);
});

it('refuses to re-triage an already-triaged case', function () {
    $this->svc->triage($this->report, $this->investigator, WhistleblowerSeverity::Low);

    expect(fn () => $this->svc->triage($this->report->fresh(), $this->investigator, WhistleblowerSeverity::High))
        ->toThrow(\DomainException::class, 'already been triaged');
});

it('logs an investigation action with encrypted notes', function () {
    $this->svc->triage($this->report, $this->investigator, WhistleblowerSeverity::Medium);

    $action = $this->svc->logAction(
        report:       $this->report->fresh(),
        investigator: $this->investigator,
        type:         InvestigationActionType::Interview,
        notes:        'Interviewed witness A — gave consistent timeline.',
        meta:         ['witness' => 'A'],
    );

    expect($action->action_type)->toBe(InvestigationActionType::Interview);
    expect($action->notes)->toBe('Interviewed witness A — gave consistent timeline.');

    // Check ciphertext on raw DB row
    $raw = \DB::table('whistleblower_actions')->where('id', $action->id)->first();
    expect($raw->notes)->not->toContain('Interviewed witness A');
});

it('changes status and records closure summary when closing', function () {
    $this->svc->triage($this->report, $this->investigator, WhistleblowerSeverity::Medium);

    $r = $this->svc->changeStatus(
        report:         $this->report->fresh(),
        investigator:   $this->investigator,
        newStatus:      WhistleblowerStatus::ClosedSubstantiated,
        closureSummary: 'Allegation confirmed; referred to CAGD for recovery.',
    );

    expect($r->status)->toBe(WhistleblowerStatus::ClosedSubstantiated);
    expect($r->closed_at)->not->toBeNull();
    expect($r->closure_summary)->toContain('confirmed');
});

it('refuses to re-open a closed case via status change', function () {
    $this->svc->triage($this->report, $this->investigator, WhistleblowerSeverity::Low);
    $this->svc->changeStatus(
        report:         $this->report->fresh(),
        investigator:   $this->investigator,
        newStatus:      WhistleblowerStatus::ClosedUnsubstantiated,
        closureSummary: 'No evidence of misconduct.',
    );

    expect(fn () => $this->svc->changeStatus(
        $this->report->fresh(),
        $this->investigator,
        WhistleblowerStatus::Investigating,
    ))->toThrow(\DomainException::class, 'Cannot re-open');
});

it('posts a message to the submitter and logs an action', function () {
    $this->svc->triage($this->report, $this->investigator, WhistleblowerSeverity::Low);

    $m = $this->svc->postMessageToSubmitter(
        $this->report->fresh(),
        $this->investigator,
        'Could you describe how you became aware of the irregularity?',
    );

    expect($m->direction)->toBe('outbound');
    expect($m->body)->toContain('became aware');
    expect($this->report->fresh()->actions()
        ->where('action_type', InvestigationActionType::MessageSent->value)->count())->toBe(1);
});

it('submitter can post a message via tracking code without authentication', function () {
    $this->svc->triage($this->report, $this->investigator, WhistleblowerSeverity::Low);

    $msg = $this->submit->postSubmitterMessage($this->report->fresh(), 'I have more documents to share.');

    expect($msg->direction)->toBe('inbound');
    expect($msg->posted_by)->toBeNull(); // anonymous — never linked to a user
    expect($msg->body)->toBe('I have more documents to share.');
});
