<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StorePaymentIntentRequest;
use App\Http\Resources\Finance\PaymentIntentResource;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Services\Finance\PaymentIntentService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentIntentController extends Controller
{
    public function __construct(private readonly PaymentIntentService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'customer_id']);

        $q = PaymentIntent::query()->with(['customer:id,code,name', 'invoice:id,reference']);
        if (! empty($filters['status']))      $q->where('status', $filters['status']);
        if (! empty($filters['customer_id'])) $q->where('customer_id', $filters['customer_id']);

        $intents = $q->orderByDesc('created_at')->paginate(50)->withQueryString();

        return Inertia::render('Finance/PaymentIntents/Index', [
            'activeModule' => 'finance-payment-intents',
            'intents'      => PaymentIntentResource::collection($intents),
            'filters'      => $filters,
            'customers'    => Customer::active()->orderBy('name')->get(['id','code','name','email']),
            'openInvoices' => ArInvoice::open()
                ->with('customer:id,code,name')
                ->orderBy('invoice_date')
                ->get(['id','reference','customer_id','customer_invoice_no','total','amount_received','invoice_date']),
        ]);
    }

    public function show(PaymentIntent $paymentIntent): Response
    {
        $paymentIntent->load(['customer', 'invoice', 'receipt']);

        return Inertia::render('Finance/PaymentIntents/Index', [
            'activeModule' => 'finance-payment-intents',
            'focusIntent'  => (new PaymentIntentResource($paymentIntent))->resolve(),
        ]);
    }

    public function store(StorePaymentIntentRequest $request): RedirectResponse
    {
        $invoice = ArInvoice::findOrFail($request->validated('ar_invoice_id'));

        try {
            $this->service->createForInvoice(
                $invoice,
                (float) $request->validated('amount'),
                $request->user(),
                $request->validated('callback_url'),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Payment link generated.');
    }
}
