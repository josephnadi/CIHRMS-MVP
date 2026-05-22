<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChartOfAccountsService
{
    public function list(array $filters = []): Collection
    {
        $q = GlAccount::query()->with('balance');

        if (! empty($filters['type'])) {
            $q->where('type', $filters['type']);
        }

        if (! empty($filters['search'])) {
            $term = trim($filters['search']);
            $q->where(function ($w) use ($term) {
                $w->where('code', 'like', "%{$term}%")
                  ->orWhere('name', 'like', "%{$term}%");
            });
        }

        if (array_key_exists('is_active', $filters)) {
            $q->where('is_active', (bool) $filters['is_active']);
        }

        return $q->orderBy('code')->get();
    }

    public function tree(): Collection
    {
        $all = GlAccount::query()
            ->with('balance')
            ->orderBy('code')
            ->get();

        $byParent = $all->groupBy('parent_id');

        $attach = function (GlAccount $node) use (&$attach, $byParent) {
            $node->setRelation('children', ($byParent->get($node->id) ?? collect())->each($attach));
            return $node;
        };

        return ($byParent->get(null) ?? collect())->each($attach);
    }

    public function create(array $data): GlAccount
    {
        return DB::transaction(function () use ($data) {
            $account = GlAccount::create($data);
            GlAccountBalance::firstOrCreate(
                ['gl_account_id' => $account->id],
                ['balance' => 0]
            );
            return $account->load('balance');
        });
    }

    public function update(GlAccount $account, array $data): GlAccount
    {
        $account->update($data);
        return $account->fresh('balance');
    }

    public function archive(GlAccount $account): void
    {
        // Preserve referential integrity for tree views and bank-account links.
        // SoftDeletes does not trigger FK constraints, so we enforce in-app.
        if ($account->children()->exists()) {
            throw new \DomainException("Cannot archive {$account->code}: it has child accounts. Reparent or archive the children first.");
        }
        if ($account->bankAccount()->exists()) {
            throw new \DomainException("Cannot archive {$account->code}: an organisational bank account is linked to it. Archive that bank account first.");
        }

        $account->delete();
    }
}
