<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class OrgBankAccountService
{
    public function list(array $filters = []): Collection
    {
        $q = OrgBankAccount::query()->with('glAccount');

        if (! empty($filters['purpose']))   $q->where('purpose', $filters['purpose']);
        if (array_key_exists('is_active', $filters)) $q->where('is_active', (bool) $filters['is_active']);

        return $q->orderBy('bank_name')->orderBy('account_name')->get();
    }

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $q = OrgBankAccount::query()->with('glAccount');
        if (! empty($filters['purpose'])) $q->where('purpose', $filters['purpose']);

        return $q->orderBy('bank_name')->paginate($perPage)->withQueryString();
    }

    public function create(array $data): OrgBankAccount
    {
        $this->assertGlAccountIsAsset((int) $data['gl_account_id']);
        return OrgBankAccount::create($data);
    }

    public function update(OrgBankAccount $account, array $data): OrgBankAccount
    {
        if (isset($data['gl_account_id']) && (int) $data['gl_account_id'] !== $account->gl_account_id) {
            $this->assertGlAccountIsAsset((int) $data['gl_account_id']);
        }
        $account->update($data);
        return $account->fresh('glAccount');
    }

    public function archive(OrgBankAccount $account): void
    {
        $account->delete();
    }

    private function assertGlAccountIsAsset(int $glAccountId): void
    {
        $gl = GlAccount::findOrFail($glAccountId);
        if ($gl->type !== GlAccountType::Asset) {
            throw new DomainException('Linked GL account must be of type asset.');
        }
    }
}
