<?php

namespace App\Services\Privacy;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Assembles a self-contained ZIP package for an Access / Portability /
 * Information request under DPA 2012 (Act 843) §§17, 21, 22.
 *
 * Walks every table where the user is the data subject (employee record,
 * leave history, payroll lines, attendance, performance reviews, training,
 * tickets, complaints THEY FILED, audit-log entries WHERE THEY ARE THE
 * ACTOR). Outputs JSON for portability + a `MANIFEST.md` cover sheet that
 * lists every file with a SHA-256 hash for tamper-evidence.
 *
 * Explicitly EXCLUDED (never exported even on request):
 *   - whistleblower content where they are the SUBJECT, not the submitter
 *     (Act 720 anonymity supersedes Act 843 access right)
 *   - other users' PII visible to this user via their role (we only export
 *     data ABOUT the subject, not data the subject has VIEWED)
 *   - encrypted 2FA secrets and recovery codes
 */
class DataSubjectExportBuilder
{
    public const VERSION = '1.0';

    /**
     * @return array{path:string, sha256:string}
     */
    public function buildFor(User $subject, string $reference): array
    {
        $workDir = storage_path("app/dsr-exports/{$reference}-" . now()->format('Ymd-His'));
        @mkdir($workDir, 0775, true);

        $files = [];

        // Core identity
        $files[] = $this->writeJson("{$workDir}/00-account.json", $this->accountPayload($subject));

        // Employment record
        if ($emp = $subject->employee) {
            $files[] = $this->writeJson("{$workDir}/01-employee.json", $this->employeePayload($emp));
            $files[] = $this->writeJson("{$workDir}/02-leave_requests.json", $emp->leaveRequests()->get()->toArray());
            $files[] = $this->writeJson("{$workDir}/03-payroll_lines.json", $this->payrollLines($emp));
            $files[] = $this->writeJson("{$workDir}/04-attendance.json", $this->attendance($emp));
            $files[] = $this->writeJson("{$workDir}/05-performance_reviews.json", $this->reviews($emp));
            $files[] = $this->writeJson("{$workDir}/06-loan_accounts.json", $emp->loans = $emp->loans ?? collect());
            $files[] = $this->writeJson("{$workDir}/07-skills.json", $emp->skills()->get()->toArray());
            $files[] = $this->writeJson("{$workDir}/08-identity_verifications.json", $this->identityVerifications($emp));
        }

        // Things they filed themselves
        $files[] = $this->writeJson("{$workDir}/09-tickets_filed.json", $this->ticketsFiled($subject));
        $files[] = $this->writeJson("{$workDir}/10-complaints_filed.json", $this->complaintsFiled($subject));
        $files[] = $this->writeJson("{$workDir}/11-job_applications.json", $this->jobApplications($subject));

        // Audit footprint
        $files[] = $this->writeJson("{$workDir}/12-audit_log_entries.json", $this->auditEntries($subject));

        // Cover sheet
        $manifest = $this->writeManifest("{$workDir}/MANIFEST.md", $files, $subject);
        $files[] = $manifest;

        // Zip it up
        $zipPath = storage_path("app/dsr-exports/{$reference}.zip");
        $this->makeZip($workDir, $zipPath);

        return [
            'path'   => $zipPath,
            'sha256' => hash_file('sha256', $zipPath),
        ];
    }

    // ── Per-section payload builders ────────────────────────────────────

    private function accountPayload(User $u): array
    {
        return [
            'id'                       => $u->id,
            'name'                     => $u->name,
            'email'                    => $u->email,
            'staff_id'                 => $u->staff_id,
            'role'                     => $u->role?->value ?? $u->role,
            'two_factor_enrolled'      => $u->two_factor_confirmed_at !== null,
            'whatsapp_phone'           => $u->whatsapp_phone,
            'notification_preferences' => $u->notification_channels,
            'created_at'               => optional($u->created_at)->toIso8601String(),
            'last_login_at'            => null, // not currently tracked separately
        ];
    }

    private function employeePayload($emp): array
    {
        return [
            'employee_no'    => $emp->employee_no,
            'department'     => $emp->department?->name,
            'position'       => $emp->position,
            'hire_date'      => optional($emp->hire_date)->toDateString(),
            'status'         => $emp->status?->value ?? $emp->status,
            'gender'         => $emp->gender,
            'date_of_birth'  => optional($emp->date_of_birth)->toDateString(),
            'national_id'    => $emp->national_id,
            'ssnit_number'   => $emp->ssnit_number,
            'tin_number'     => $emp->tin_number,
            'address'        => $emp->address,
            'phone'          => $emp->phone,
            'emergency'      => [
                'name'         => $emp->emergency_contact_name,
                'phone'        => $emp->emergency_contact_phone,
                'relationship' => $emp->emergency_contact_relationship,
            ],
            'bank' => [
                'name'    => $emp->bank_name,
                'account' => $emp->bank_account,
            ],
            'mobile_money' => [
                'channel' => $emp->disbursement_channel,
                'number'  => $emp->mobile_money_number,
                'network' => $emp->mobile_money_network,
            ],
            'salary'         => $emp->salary !== null ? (float) $emp->salary : null,
            'current_grade'  => $emp->currentGrade?->code,
            'current_step'   => $emp->current_step,
        ];
    }

    private function payrollLines($emp): array
    {
        return \App\Models\PayrollLine::where('employee_id', $emp->id)
            ->with(['run:id,reference,period_year,period_month'])
            ->get()
            ->map(fn ($l) => [
                'reference' => $l->run?->reference,
                'period'    => sprintf('%04d-%02d', $l->run?->period_year ?? 0, $l->run?->period_month ?? 0),
                'basic'     => (float) $l->basic,
                'gross'     => (float) $l->gross,
                'paye'      => (float) $l->paye,
                'ssnit'     => (float) $l->ssnit_tier1_employee,
                'tier2'     => (float) $l->tier2_employer,
                'net'       => (float) $l->net,
                'status'    => $l->status,
            ])->all();
    }

    private function attendance($emp): array
    {
        return \App\Models\AttendanceSummary::where('employee_id', $emp->id)
            ->orderBy('summary_date')
            ->get()
            ->map(fn ($s) => [
                'date'         => optional($s->summary_date)->toDateString(),
                'status'       => $s->status?->value ?? $s->status,
                'first_in'     => $s->first_in,
                'last_out'     => $s->last_out,
                'hours_worked' => (float) $s->hours_worked,
            ])->all();
    }

    private function reviews($emp): array
    {
        return \App\Models\Review::where('employee_id', $emp->id)
            ->get()
            ->map(fn ($r) => [
                'cycle_id'        => $r->cycle_id,
                'type'            => $r->type,
                'overall_rating'  => $r->overall_rating !== null ? (float) $r->overall_rating : null,
                'status'          => $r->status,
                'submitted_at'    => optional($r->submitted_at)->toIso8601String(),
                'comments'        => $r->comments,
            ])->all();
    }

    private function identityVerifications($emp): array
    {
        return \App\Models\IdentityVerification::where('employee_id', $emp->id)
            ->get()
            ->map(fn ($v) => [
                'provider'    => $v->provider?->value,
                'status'      => $v->status?->value,
                'verified_at' => optional($v->verified_at)->toIso8601String(),
                'expires_at'  => optional($v->expires_at)->toIso8601String(),
                'card_tail'   => $v->ghana_card_number ? substr($v->ghana_card_number, -1) : null,
            ])->all();
    }

    private function ticketsFiled(User $u): array
    {
        return \App\Models\Ticket::query()
            ->whereHas('employee', fn ($q) => $q->where('user_id', $u->id))
            ->get()->toArray();
    }

    private function complaintsFiled(User $u): array
    {
        return \App\Models\Complaint::where('reporter_user_id', $u->id)
            ->orWhereHas('employee', fn ($q) => $q->where('user_id', $u->id))
            ->get()->toArray();
    }

    private function jobApplications(User $u): array
    {
        return \App\Models\Applicant::where('email', $u->email)->get()->toArray();
    }

    private function auditEntries(User $u): array
    {
        return \App\Models\AuditLog::where('user_id', $u->id)
            ->orderBy('chain_position')
            ->limit(5000)   // cap to keep export reasonable
            ->get()
            ->map(fn ($a) => [
                'at'         => optional($a->created_at)->toIso8601String(),
                'action'     => $a->action,
                'method'     => $a->method,
                'path'       => $a->path,
                'ip_address' => $a->ip_address,
            ])->all();
    }

    private function writeJson(string $path, mixed $payload): string
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $path;
    }

    private function writeManifest(string $path, array $files, User $subject): string
    {
        $lines = [
            "# Data-Subject Export — {$subject->name}",
            '',
            'Generated under Ghana Data Protection Act 2012 (Act 843) §§17 / 21 / 22.',
            "Subject user ID: {$subject->id}",
            "Generated at: " . now()->toIso8601String(),
            "Pack version: " . self::VERSION,
            '',
            '## Files (each file is independently SHA-256 hashed)',
            '',
            '| Path | SHA-256 | Bytes |',
            '|------|---------|-------|',
        ];

        foreach ($files as $file) {
            if (! file_exists($file)) continue;
            $rel = basename($file);
            $lines[] = sprintf('| `%s` | `%s` | %s |', $rel, hash_file('sha256', $file), number_format(filesize($file)));
        }

        $lines[] = '';
        $lines[] = '## Statutory exclusions';
        $lines[] = '';
        $lines[] = '- **Whistleblower content** where the subject is named as a respondent is excluded under Whistleblower Act 2006 (Act 720); the segregated investigator handles such disclosures.';
        $lines[] = '- **2FA secrets and recovery codes** are excluded as they exist solely to authenticate the subject — disclosing them would weaken account security.';
        $lines[] = '- **Other users\' personal data** that the subject may have viewed in the course of their role is not included; this export contains data ABOUT the subject only.';

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function makeZip(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot open zip {$zipPath}.");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $local = ltrim(str_replace($sourceDir, '', $file->getRealPath()), '/\\');
            $zip->addFile($file->getRealPath(), $local);
        }
        $zip->close();
    }
}
