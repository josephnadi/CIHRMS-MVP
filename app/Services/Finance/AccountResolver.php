<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\Finance\MissingAccountMappingException;
use App\Models\GlAccount;
use App\Models\PostingAccount;

class AccountResolver
{
    public function resolve(string $slug): GlAccount
    {
        $rule = PostingAccount::where('slug', $slug)->first();

        if (! $rule) {
            throw new MissingAccountMappingException(
                "No posting-account mapping found for slug '{$slug}'. Map it under Finance → Posting Rules."
            );
        }

        $account = GlAccount::where('id', $rule->gl_account_id)->where('is_active', true)->first();

        if (! $account) {
            throw new MissingAccountMappingException(
                "Posting slug '{$slug}' maps to an inactive or missing GL account (id {$rule->gl_account_id})."
            );
        }

        return $account;
    }
}
