<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CertificationExpired;
use App\Events\CertificationExpiring;
use App\Events\PolicyAcknowledged;
use App\Events\PolicyDrafted;
use App\Events\PolicyPublished;
use App\Events\PolicyVersionAdded;
use App\Models\Certification;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GovernanceService
{
    public function createPolicy(User $owner, array $data): Policy
    {
        return DB::transaction(function () use ($owner, $data) {
            $data['owner_user_id'] = $owner->id;
            $data['slug'] = $data['slug'] ?? Str::slug($data['title']);

            $initialBody = $data['initial_body'] ?? '# Draft policy body';
            unset($data['initial_body']);

            $policy = Policy::create($data);

            PolicyVersion::create([
                'policy_id'      => $policy->id,
                'version_number' => 1,
                'body'           => $initialBody,
                'changelog'      => 'Initial draft',
            ]);

            PolicyDrafted::dispatch($policy, $owner);
            return $policy->fresh();
        });
    }

    public function addVersion(Policy $policy, User $author, string $body, ?string $changelog = null): PolicyVersion
    {
        $maxVersion = (int) ($policy->versions()->max('version_number') ?? 0);
        $version = PolicyVersion::create([
            'policy_id'      => $policy->id,
            'version_number' => $maxVersion + 1,
            'body'           => $body,
            'changelog'      => $changelog,
        ]);

        PolicyVersionAdded::dispatch($version, $author);
        return $version;
    }

    public function publish(PolicyVersion $version, User $publisher, \DateTimeInterface $effectiveFrom): PolicyVersion
    {
        if ($version->published_at !== null) {
            throw new DomainException("Version {$version->version_number} is already published.");
        }

        return DB::transaction(function () use ($version, $publisher, $effectiveFrom) {
            $effectiveCarbon = CarbonImmutable::instance($effectiveFrom);

            $policy = $version->policy;
            if ($policy->current_version_id && $policy->current_version_id !== $version->id) {
                PolicyVersion::where('id', $policy->current_version_id)
                    ->update(['effective_to' => $effectiveCarbon->subDay()->toDateString()]);
            }

            $version->update([
                'published_at'   => now(),
                'published_by'   => $publisher->id,
                'effective_from' => $effectiveCarbon->toDateString(),
            ]);

            $policy->update(['current_version_id' => $version->id]);

            PolicyPublished::dispatch($version->fresh(), $publisher);
            return $version->fresh();
        });
    }

    public function acknowledge(
        PolicyVersion $version,
        User $user,
        string $signedFullName,
        string $ipAddress,
        string $userAgent,
    ): PolicyAcknowledgement {
        if ($version->published_at === null) {
            throw new DomainException('Cannot acknowledge an unpublished version.');
        }

        $existing = PolicyAcknowledgement::where('policy_version_id', $version->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $ack = PolicyAcknowledgement::create([
            'policy_version_id' => $version->id,
            'user_id'           => $user->id,
            'acknowledged_at'   => now(),
            'ip_address'        => $ipAddress,
            'user_agent'        => $userAgent,
            'signed_full_name'  => $signedFullName,
        ]);

        PolicyAcknowledged::dispatch($ack, $user);
        return $ack;
    }

    public function pendingAcksFor(User $user): Collection
    {
        return Policy::query()
            ->active()
            ->whereNotNull('current_version_id')
            ->with('currentVersion')
            ->get()
            ->filter(function (Policy $p) use ($user) {
                if (! $p->currentVersion?->published_at) return false;
                return ! PolicyAcknowledgement::where('policy_version_id', $p->current_version_id)
                    ->where('user_id', $user->id)
                    ->exists();
            })
            ->values();
    }

    public function recordCertification(Employee $employee, array $data): Certification
    {
        return Certification::create(array_merge(
            ['employee_id' => $employee->id],
            $data,
        ));
    }

    public function dispatchExpiryReminders(int $daysAhead = 30): int
    {
        $count = 0;

        Certification::query()
            ->needingReminder($daysAhead)
            ->with('employee.user')
            ->chunkById(100, function ($chunk) use (&$count) {
                foreach ($chunk as $cert) {
                    if (! $cert->expires_at) continue;

                    if ($cert->expires_at->isPast()) {
                        CertificationExpired::dispatch($cert);
                    } else {
                        CertificationExpiring::dispatch($cert);
                    }

                    $cert->update(['reminder_sent_at' => now()]);
                    $count++;
                }
            });

        return $count;
    }
}
