<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ArInvoiceStatus;
use App\Models\Customer;
use DomainException;
use Illuminate\Support\Collection;

class CustomerService
{
    public function list(array $filters = []): Collection
    {
        $q = Customer::query()->with(['defaultArGl:id,code,name', 'defaultIncomeGl:id,code,name']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = trim($filters['search']);
            $q->where(function ($w) use ($term) {
                $w->where('name', 'like', "%{$term}%")
                  ->orWhere('code', 'like', "%{$term}%")
                  ->orWhere('tax_id', 'like', "%{$term}%");
            });
        }

        return $q->orderBy('name')->get();
    }

    public function create(array $data): Customer
    {
        return Customer::create($data);
    }

    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);
        return $customer->fresh();
    }

    /**
     * Archive guard — refuses if any invoice exists that is not Cancelled
     * or WrittenOff. Both terminal states are excluded because they have no
     * outstanding balance the system needs to keep the customer record alive for.
     */
    public function archive(Customer $customer): void
    {
        $openCount = $customer->invoices()
            ->whereNotIn('status', [
                ArInvoiceStatus::Cancelled->value,
                ArInvoiceStatus::WrittenOff->value,
            ])
            ->count();

        if ($openCount > 0) {
            throw new DomainException(
                "Cannot archive customer {$customer->code}: {$openCount} non-cancelled/non-written-off invoices."
            );
        }

        $customer->delete();
    }
}
