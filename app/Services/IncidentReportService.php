<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Events\Incident\IncidentMessagePosted;
use App\Events\Incident\IncidentReportAssigned;
use App\Events\Incident\IncidentReportClosed;
use App\Events\Incident\IncidentReportReopened;
use App\Events\Incident\IncidentReportUnassigned;
use App\Models\IncidentReport;
use App\Models\IncidentReportAttachment;
use App\Models\IncidentReportMessage;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IncidentReportService
{
    public function list(Request $request): LengthAwarePaginator
    {
        return IncidentReport::with(['employee.user', 'currentAssignees'])
            ->visibleTo($request->user())
            ->when($request->category, fn ($q, $v) => $q->where('category', $v))
            ->when($request->status,   fn ($q, $v) => $q->where('status', $v))
            ->when($request->q,        fn ($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->latest()
            ->paginate(20)
            ->withQueryString();
    }

    public function create(User $author, array $data, array $files = []): IncidentReport
    {
        if (! $author->employee) {
            throw ValidationException::withMessages(['employee_id' => 'You must have an employee profile to submit an incident report.']);
        }

        return DB::transaction(function () use ($author, $data, $files) {
            $report = IncidentReport::create([
                'employee_id' => $author->employee->id,
                'category'    => $data['category'],
                'title'       => $data['title'],
                'body'        => $data['body'],
                'status'      => IncidentStatus::Open,
            ]);

            foreach ($files as $file) {
                $this->attachFile($report, $file, $author);
            }

            return $report->fresh(['attachments']);
        });
    }

    public function update(IncidentReport $report, array $data): IncidentReport
    {
        $report->update([
            'title' => $data['title'],
            'body'  => $data['body'],
        ]);
        return $report->fresh();
    }

    public function assign(IncidentReport $report, int $userId, User $actor): void
    {
        $target = User::findOrFail($userId);

        if (! $this->userHoldsReviewerPermission($target)) {
            throw ValidationException::withMessages(['user_id' => 'Selected user does not hold the incidents.review permission.']);
        }

        // Already assigned and active?
        $existing = $report->assignees()->where('users.id', $target->id)->first();
        if ($existing && $existing->pivot->removed_at === null) {
            return;
        }

        DB::transaction(function () use ($report, $target, $actor, $existing) {
            if ($existing) {
                $report->assignees()->updateExistingPivot($target->id, [
                    'assigned_at'    => now(),
                    'assigned_by_id' => $actor->id,
                    'removed_at'     => null,
                ]);
            } else {
                $report->assignees()->attach($target->id, [
                    'assigned_at'    => now(),
                    'assigned_by_id' => $actor->id,
                ]);
            }

            if ($report->status === IncidentStatus::Open) {
                $report->update(['status' => IncidentStatus::InReview]);
            }
        });

        event(new IncidentReportAssigned($report->fresh(), $target, $actor));
    }

    public function unassign(IncidentReport $report, int $userId, User $actor): void
    {
        $report->assignees()->updateExistingPivot($userId, ['removed_at' => now()]);
        $user = User::find($userId);
        if ($user) {
            event(new IncidentReportUnassigned($report->fresh(), $user, $actor));
        }
    }

    public function postMessage(IncidentReport $report, User $author, array $data, array $files = []): IncidentReportMessage
    {
        return DB::transaction(function () use ($report, $author, $data, $files) {
            $message = IncidentReportMessage::create([
                'incident_report_id' => $report->id,
                'author_id'          => $author->id,
                'body'               => $data['body'],
            ]);

            foreach ($files as $file) {
                $this->attachFile($message, $file, $author);
            }

            event(new IncidentMessagePosted($message->fresh(['attachments'])));
            return $message;
        });
    }

    public function close(IncidentReport $report, User $actor, ?string $note): void
    {
        $report->update([
            'status'          => IncidentStatus::Closed,
            'closed_at'       => now(),
            'closed_by_id'    => $actor->id,
            'resolution_note' => $note,
        ]);
        event(new IncidentReportClosed($report->fresh(), $actor));
    }

    public function reopen(IncidentReport $report, User $actor): void
    {
        $report->update([
            'status'          => IncidentStatus::InReview,
            'closed_at'       => null,
            'closed_by_id'    => null,
            'resolution_note' => null,
        ]);
        event(new IncidentReportReopened($report->fresh(), $actor));
    }

    /** Holders pool: users with the permission via role OR per-user permissions JSON. */
    public function eligibleReviewers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where(function ($q) {
                $q->whereHas('roles.permissions', fn ($p) => $p->where('slug', 'incidents.review'))
                  ->orWhereJsonContains('permissions', 'incidents.review');
            })
            ->select('id', 'name', 'role')
            ->orderBy('name')
            ->get();
    }

    private function userHoldsReviewerPermission(User $user): bool
    {
        if (in_array('incidents.review', (array) ($user->permissions ?? []), true)) {
            return true;
        }
        return $user->roles()
            ->whereHas('permissions', fn ($q) => $q->where('slug', 'incidents.review'))
            ->exists();
    }

    private function attachFile($attachable, UploadedFile $file, User $uploader): IncidentReportAttachment
    {
        // When the attachable is a freshly-created Message, $attachable->report
        // is a lazy relation that trips strict mode. Eager-load defensively.
        if (! $attachable instanceof IncidentReport) {
            $attachable->loadMissing('report');
        }
        $dir  = ($attachable instanceof IncidentReport ? $attachable->id : $attachable->report->id);
        $name = Str::uuid() . '-' . $file->getClientOriginalName();
        $path = $file->storeAs((string) $dir, $name, 'incidents');

        return $attachable->attachments()->create([
            'file_path'      => $path,
            'original_name'  => $file->getClientOriginalName(),
            'mime_type'      => $file->getMimeType(),
            'size_bytes'     => $file->getSize(),
            'uploaded_by_id' => $uploader->id,
        ]);
    }
}
