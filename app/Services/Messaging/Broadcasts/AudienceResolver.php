<?php

declare(strict_types=1);

namespace App\Services\Messaging\Broadcasts;

use App\Enums\BroadcastAudienceType;
use App\Enums\EmployeeStatus;
use App\Enums\MemberStatus;
use App\Models\Employee;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves a BroadcastAudienceType + params into an Eloquent Builder that
 * the DispatchBroadcastJob will chunkById() through. Each audience type
 * binds to one recipient model class — verify the binding via
 * BroadcastAudienceType::recipientClass().
 *
 * Returning a Builder (not a Collection) keeps memory bounded for large
 * audiences: the job can chunk through millions of rows without loading
 * them all at once.
 *
 * Relation note: Member→Customer uses belongsTo('customer'), Customer has
 * invoices() HasMany on ArInvoice. The outstanding-fees query therefore
 * walks 'customer.invoices' (not 'customer.arInvoices').
 */
class AudienceResolver
{
    public function resolve(BroadcastAudienceType $type, array $params): Builder
    {
        return match ($type) {
            BroadcastAudienceType::AllActiveMembers
                => Member::query()->where('status', MemberStatus::Active->value),

            BroadcastAudienceType::MembersByClass
                => Member::query()
                    ->where('status', MemberStatus::Active->value)
                    ->where('class', $params['class'] ?? null),

            BroadcastAudienceType::MembersWithOutstandingFees
                => Member::query()
                    ->where('status', MemberStatus::Active->value)
                    ->whereHas('customer.invoices', function (Builder $q) {
                        $q->whereRaw('total > amount_received');
                    }),

            BroadcastAudienceType::AllActiveEmployees
                => Employee::query()->where('status', EmployeeStatus::Active->value),

            BroadcastAudienceType::EmployeesByDepartment
                => Employee::query()
                    ->where('status', EmployeeStatus::Active->value)
                    ->where('department_id', $params['department_id'] ?? null),

            BroadcastAudienceType::UsersByPermission
                => User::query()
                    ->whereJsonContains('permissions', $params['permission'] ?? '__none__'),
        };
    }
}
