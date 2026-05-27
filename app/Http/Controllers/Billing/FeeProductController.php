<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreFeeProductRequest;
use App\Http\Requests\Billing\UpdateFeeProductRequest;
use App\Http\Resources\Billing\FeeProductResource;
use App\Models\FeeProduct;
use App\Models\GlAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeeProductController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FeeProduct::class);

        $filters = $request->only(['q', 'is_active']);

        $products = FeeProduct::query()
            ->with('incomeGl')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($qq) use ($term) {
                    $qq->where('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%");
                });
            })
            ->when(
                isset($filters['is_active']) && $filters['is_active'] !== '',
                fn ($q) => $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOL))
            )
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Billing/FeeCatalog/Index', [
            'activeModule'   => 'billing-fee-catalog',
            'products'       => FeeProductResource::collection($products),
            'filters'        => $filters,
            'incomeAccounts' => GlAccount::ofType('income')->active()
                ->orderBy('code')->get(['id','code','name']),
        ]);
    }

    public function store(StoreFeeProductRequest $request): RedirectResponse
    {
        $product = FeeProduct::create($request->validated());
        return back()->with('success', "Fee product {$product->code} created.");
    }

    public function update(UpdateFeeProductRequest $request, FeeProduct $feeProduct): RedirectResponse
    {
        $feeProduct->fill($request->validated())->save();
        return back()->with('success', "Fee product {$feeProduct->code} updated.");
    }

    public function destroy(FeeProduct $feeProduct, Request $request): RedirectResponse
    {
        $this->authorize('delete', $feeProduct);
        $feeProduct->delete();
        return back()->with('success', "Fee product {$feeProduct->code} retired.");
    }
}
