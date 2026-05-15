<?php

namespace App\Services\Whistleblower;

use App\Enums\WhistleblowerCategory;
use App\Enums\WhistleblowerStatus;
use App\Events\WhistleblowerReportSubmitted;
use App\Models\User;
use App\Models\WhistleblowerEvidence;
use App\Models\WhistleblowerMessage;
use App\Models\WhistleblowerReport;
use App\Models\WhistleblowerSubject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Public-facing intake service. The only writes to whistleblower_reports
 * during anonymous flow go through here. Returns the plaintext tracking
 * code exactly once — the caller is responsible for displaying it to the
 * submitter and then discarding it.
 */
class WhistleblowerSubmissionService
{
    public function __construct(private readonly TrackingCodeGenerator $tokens) {}

    /**
     * @param  array{
     *     category:string,
     *     subject_summary:string,
     *     description:string,
     *     desired_outcome?:?string,
     *     incident_location?:?string,
     *     incident_date?:?string,
     *     submitter_contact?:?string,
     *     is_anonymous?:bool,
     *     subjects?:array<int, array{label:string, role_context?:?string, linked_employee_id?:?int}>,
     *     intake_source?:string,
     * } $payload
     * @param  array<int, UploadedFile> $files
     *
     * @return array{report:WhistleblowerReport, tracking_code:string}
     */
    public function submit(array $payload, ?User $authenticatedUser = null, array $files = []): array
    {
        $code = $this->tokens->generate();
        $hash = WhistleblowerReport::hashTrackingCode($code);

        return DB::transaction(function () use ($payload, $authenticatedUser, $files, $code, $hash) {
            $isAnon = (bool) ($payload['is_anonymous'] ?? true);

            $report = WhistleblowerReport::create([
                'case_number'            => $this->nextCaseNumber(),
                'tracking_token_hash'    => $hash,
                'category'               => $payload['category'],
                'status'                 => WhistleblowerStatus::Submitted->value,
                'subject_summary'        => substr((string) $payload['subject_summary'], 0, 255),
                'incident_date'          => $payload['incident_date'] ?? null,
                'description'            => (string) $payload['description'],
                'desired_outcome'        => $payload['desired_outcome'] ?? null,
                'incident_location'      => $payload['incident_location'] ?? null,
                'submitter_contact'      => $payload['submitter_contact'] ?? null,
                'is_anonymous'           => $isAnon,
                // Hard enforcement: anonymous submissions NEVER carry a user_id.
                'submitter_user_id'      => $isAnon ? null : $authenticatedUser?->id,
                'received_at'            => now(),
                'intake_source'          => $payload['intake_source'] ?? 'web_form',
            ]);

            foreach ($payload['subjects'] ?? [] as $s) {
                if (empty($s['label'])) continue;
                WhistleblowerSubject::create([
                    'report_id'          => $report->id,
                    'subject_label'      => (string) $s['label'],
                    'role_context'       => $s['role_context'] ?? null,
                    'linked_employee_id' => $s['linked_employee_id'] ?? null,
                ]);
            }

            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) continue;
                $stored = $file->store('whistleblower/' . $report->id, 'local');
                WhistleblowerEvidence::create([
                    'report_id'         => $report->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'storage_path'      => $stored,
                    'mime_type'         => $file->getMimeType(),
                    'size_bytes'        => $file->getSize(),
                    'uploaded_by'       => $isAnon ? null : $authenticatedUser?->id,
                ]);
            }

            event(new WhistleblowerReportSubmitted($report));

            return ['report' => $report, 'tracking_code' => $code];
        });
    }

    /**
     * Submitter-side reply via the tracking code. No authentication; identity
     * is proved by possession of the original code.
     */
    public function postSubmitterMessage(WhistleblowerReport $report, string $body): WhistleblowerMessage
    {
        return WhistleblowerMessage::create([
            'report_id'  => $report->id,
            'direction'  => 'inbound',
            'body'       => $body,
            'posted_by'  => null,           // anonymous: never link to a user
            'posted_at'  => now(),
        ]);
    }

    private function nextCaseNumber(): string
    {
        $year  = now()->year;
        $count = WhistleblowerReport::whereYear('created_at', $year)->count() + 1;
        return sprintf('WB-%04d-%05d', $year, $count);
    }
}
