<?php

declare(strict_types=1);

namespace App\Services\Messaging\Broadcasts;

use App\Enums\BroadcastAudienceType;
use App\Models\Employee;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Whitelist-enforced {{var}} interpolation. The audience type binds the
 * recipient class + allowed variable names. Unknown vars render as empty
 * string — vars outside the whitelist NEVER reach $recipient introspection,
 * preventing accidental leak of sensitive attributes like `user.password`.
 */
class TemplateRenderer
{
    public function render(string $body, object $recipient, BroadcastAudienceType $type): string
    {
        $allowed = $type->allowedVariables();

        return preg_replace_callback('/\{\{\s*([a-z_][a-z_0-9]*(?:\.[a-z_][a-z_0-9]*)?)\s*\}\}/i',
            function ($m) use ($allowed, $recipient) {
                $var = $m[1];
                if (! in_array($var, $allowed, true)) {
                    return '';
                }
                return $this->resolveVariable($var, $recipient);
            },
            $body,
        );
    }

    private function resolveVariable(string $var, object $recipient): string
    {
        return match ($var) {
            'org_name' => (string) config('app.name'),
            'today'    => Carbon::now()->toDateString(),

            'member.name'              => (string) ($recipient instanceof Member ? $recipient->name : ''),
            'member.member_no'         => (string) ($recipient instanceof Member ? $recipient->member_no : ''),
            'member.class'             => $recipient instanceof Member ? $recipient->class?->label() ?? '' : '',
            'member.outstanding_total' => $recipient instanceof Member ? (string) $this->memberOutstanding($recipient) : '',
            'member.next_due_date'     => $recipient instanceof Member ? $this->memberNextDue($recipient) : '',

            // Employee.name + staff_id come from the linked User row;
            // position is a plain string column on Employee itself (not a relation).
            'employee.name'       => $recipient instanceof Employee ? (string) ($recipient->user?->name ?? '') : '',
            'employee.staff_id'   => $recipient instanceof Employee ? (string) ($recipient->user?->staff_id ?? '') : '',
            'employee.department' => $recipient instanceof Employee ? (string) ($recipient->department?->name ?? '') : '',
            'employee.position'   => $recipient instanceof Employee ? (string) ($recipient->position ?? '') : '',

            'user.name' => $recipient instanceof User ? (string) ($recipient->name ?? '') : '',
            'user.role' => $recipient instanceof User ? (string) ($recipient->role ?? '') : '',

            default => '',
        };
    }

    private function memberOutstanding(Member $member): float
    {
        // Sum (total - amount_received) across the member's customer's open AR invoices.
        return (float) $member->invoices()
            ->selectRaw('COALESCE(SUM(total - amount_received), 0) as outstanding')
            ->value('outstanding');
    }

    private function memberNextDue(Member $member): string
    {
        $next = $member->assignments()
            ->whereNotNull('due_date')
            ->orderBy('due_date')
            ->first();
        return $next?->due_date?->toDateString() ?? '';
    }
}
