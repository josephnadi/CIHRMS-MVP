<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreBudgetLineRequest;
use App\Models\Budget;
use App\Models\GlAccount;
use App\Services\Finance\BudgetService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets)
    {
    }

    public function index(Request $request): Response
    {
        $year   = (int) ($request->query('year') ?: now()->format('Y'));
        $budget = $this->budgets->forYear($year);
        $lines  = $budget->lines()->get()->keyBy('gl_account_id');

        return Inertia::render('Finance/Budgets/Index', [
            'activeModule' => 'finance-budgets',
            'year'         => $year,
            'budget'       => ['id' => $budget->id, 'status' => $budget->status->value, 'approved_at' => $budget->approved_at?->toDateString()],
            'accounts'     => GlAccount::active()->orderBy('code')->get(['id', 'code', 'name', 'type'])
                ->map(fn ($a) => [
                    'id'            => $a->id,
                    'code'          => $a->code,
                    'name'          => $a->name,
                    'type'          => $a->type->value,
                    'annual_amount' => (float) ($lines[$a->id]->annual_amount ?? 0),
                ]),
        ]);
    }

    public function storeLine(StoreBudgetLineRequest $request): RedirectResponse
    {
        $data    = $request->validated();
        $budget  = $this->budgets->forYear((int) $data['year']);
        $account = GlAccount::findOrFail($data['gl_account_id']);

        try {
            $this->budgets->setLine($budget, $account, (float) $data['annual_amount']);
        } catch (DomainException $e) {
            return back()->withErrors(['budget' => $e->getMessage()]);
        }

        return back()->with('success', 'Budget updated.');
    }

    public function approve(Request $request): RedirectResponse
    {
        $budget = $this->budgets->forYear((int) $request->input('year', now()->format('Y')));

        try {
            $this->budgets->approve($budget, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['budget' => $e->getMessage()]);
        }

        return back()->with('success', 'Budget approved.');
    }

    public function revert(Request $request): RedirectResponse
    {
        $budget = $this->budgets->forYear((int) $request->input('year', now()->format('Y')));
        $this->budgets->revertToDraft($budget);

        return back()->with('success', 'Budget reverted to draft.');
    }
}
