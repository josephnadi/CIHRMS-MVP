<?php

namespace App\Services;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementType;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\Goal;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AnnouncementService
{
    /**
     * Aggregate the live ticker for the given user.
     *
     * Combines:
     *  - manual announcements (DB-backed, active in current window)
     *  - upcoming birthdays (next 7 days)
     *  - upcoming events (approved leave starting in next 14 days)
     *  - new task assignments (goals assigned in last 7 days)
     */
    public function ticker(?User $user, int $limit = 25): Collection
    {
        $items = collect()
            ->concat($this->manual($user))
            ->concat($this->birthdays())
            ->concat($this->events())
            ->concat($this->tasks($user));

        // Pinned first; then by severity weight; then chronological by created/seed time
        $severityWeight = [
            AnnouncementSeverity::Urgent->value    => 0,
            AnnouncementSeverity::Important->value => 1,
            AnnouncementSeverity::Info->value      => 2,
        ];

        return $items
            ->sortBy([
                fn ($a, $b) => ($b['pinned'] ?? false) <=> ($a['pinned'] ?? false),
                fn ($a, $b) => ($severityWeight[$a['severity']] ?? 9) <=> ($severityWeight[$b['severity']] ?? 9),
                fn ($a, $b) => strcmp($a['sort_at'] ?? '', $b['sort_at'] ?? ''),
            ])
            ->values()
            ->take($limit);
    }

    protected function manual(?User $user): Collection
    {
        $roleSlug = $user?->role instanceof \BackedEnum
            ? $user->role->value
            : (is_string($user?->role) ? $user->role : null);

        return Announcement::query()
            ->activeNow()
            ->forRole($roleSlug)
            ->orderByDesc('pinned')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Announcement $a) => [
                'id'        => 'ann:'.$a->id,
                'type'      => $a->type?->value,
                'severity'  => $a->severity?->value,
                'title'     => $a->title,
                'icon'      => $a->icon ?: $a->type?->icon(),
                'link_url'  => $a->link_url,
                'pinned'    => (bool) $a->pinned,
                'sort_at'   => $a->created_at?->toIso8601String(),
            ]);
    }

    protected function birthdays(): Collection
    {
        $today = Carbon::today();
        $horizon = $today->copy()->addDays(7);

        // Pull all employees with DOB and filter in PHP for cross-DB portability
        // (MONTH/DAY math differs between SQLite/MySQL/PostgreSQL).
        return Employee::query()
            ->whereNotNull('date_of_birth')
            ->with('user:id,name')
            ->get(['id', 'user_id', 'date_of_birth'])
            ->map(function (Employee $e) use ($today, $horizon) {
                $dob = $e->date_of_birth;
                if (! $dob) return null;

                $nextBirthday = Carbon::create($today->year, $dob->month, $dob->day);
                if ($nextBirthday->lt($today)) {
                    $nextBirthday->addYear();
                }
                if ($nextBirthday->gt($horizon)) {
                    return null;
                }

                $days = (int) $today->diffInDays($nextBirthday, false);
                $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days");
                $name = $e->user?->name ?: 'A team member';

                return [
                    'id'        => 'bday:'.$e->id,
                    'type'      => AnnouncementType::Birthday->value,
                    'severity'  => AnnouncementSeverity::Info->value,
                    'title'     => $days === 0
                        ? "🎂 {$name} celebrates a birthday today"
                        : "{$name}'s birthday {$when}",
                    'icon'      => AnnouncementType::Birthday->icon(),
                    'link_url'  => null,
                    'pinned'    => false,
                    'sort_at'   => $nextBirthday->toIso8601String(),
                ];
            })
            ->filter()
            ->values();
    }

    protected function events(): Collection
    {
        $today   = Carbon::today();
        $horizon = $today->copy()->addDays(14);

        return LeaveRequest::query()
            ->where('status', 'approved')
            ->whereBetween('start_date', [$today, $horizon])
            ->with(['employee:id,user_id', 'employee.user:id,name'])
            ->limit(10)
            ->get()
            ->map(function (LeaveRequest $lr) {
                $name = $lr->employee?->user?->name ?: 'A team member';
                $start = $lr->start_date?->format('M j');
                return [
                    'id'        => 'event:'.$lr->id,
                    'type'      => AnnouncementType::Event->value,
                    'severity'  => AnnouncementSeverity::Info->value,
                    'title'     => "{$name} on approved leave from {$start}",
                    'icon'      => AnnouncementType::Event->icon(),
                    'link_url'  => null,
                    'pinned'    => false,
                    'sort_at'   => $lr->start_date?->toIso8601String(),
                ];
            });
    }

    protected function tasks(?User $user): Collection
    {
        if (! $user || ! $user->employee) {
            return collect();
        }

        $since = Carbon::now()->subDays(7);

        return Goal::query()
            ->where('employee_id', $user->employee->id)
            ->where('created_at', '>=', $since)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->limit(5)
            ->get(['id', 'title', 'created_at', 'status'])
            ->map(fn (Goal $g) => [
                'id'        => 'task:'.$g->id,
                'type'      => AnnouncementType::Task->value,
                'severity'  => AnnouncementSeverity::Important->value,
                'title'     => "New task: {$g->title}",
                'icon'      => AnnouncementType::Task->icon(),
                'link_url'  => route('performance.goals.index', absolute: false),
                'pinned'    => false,
                'sort_at'   => $g->created_at?->toIso8601String(),
            ]);
    }
}
