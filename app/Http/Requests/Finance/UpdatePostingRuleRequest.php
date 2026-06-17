<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\GlAccount;
use App\Models\PostingAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePostingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('finance.posting_rules.manage') === true;
    }

    public function rules(): array
    {
        return [
            'gl_account_id' => [
                'required',
                'integer',
                // deleted_at,NULL excludes soft-deleted (archived) accounts —
                // plain exists:gl_accounts,id would let an archived account pass.
                'exists:gl_accounts,id,deleted_at,NULL',
                function (string $attribute, mixed $value, Closure $fail) {
                    /** @var PostingAccount $rule */
                    $rule = $this->route('postingAccount');

                    if ($rule->locked) {
                        $fail('This mapping is locked and cannot be re-pointed.');
                        return;
                    }

                    $target  = GlAccount::find($value);
                    $current = $rule->glAccount;
                    if ($target && $current && $target->type !== $current->type) {
                        $fail("The account must be of type {$current->type->value} to keep postings valid.");
                    }
                    if ($target && ! $target->is_active) {
                        $fail('The target GL account is archived.');
                    }
                },
            ],
        ];
    }
}
