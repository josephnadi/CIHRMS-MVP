<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\UpdatePostingRuleRequest;
use App\Http\Resources\Finance\PostingRuleResource;
use App\Models\GlAccount;
use App\Models\PostingAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PostingRuleController extends Controller
{
    public function index(): Response
    {
        $rules = PostingAccount::with('glAccount')
            ->orderBy('domain')
            ->orderBy('slug')
            ->get();

        return Inertia::render('Finance/PostingRules/Index', [
            'activeModule' => 'finance-posting-rules',
            'rules'        => PostingRuleResource::collection($rules),
            'glAccounts'   => GlAccount::active()
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function update(UpdatePostingRuleRequest $request, PostingAccount $postingAccount): RedirectResponse
    {
        $postingAccount->update(['gl_account_id' => $request->validated()['gl_account_id']]);

        return back()->with('success', "Mapping '{$postingAccount->slug}' updated.");
    }
}
