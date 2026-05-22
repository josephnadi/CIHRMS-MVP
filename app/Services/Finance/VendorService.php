<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\VendorInvoiceStatus;
use App\Models\Vendor;
use DomainException;
use Illuminate\Support\Collection;

class VendorService
{
    public function list(array $filters = []): Collection
    {
        $q = Vendor::query()->with(['defaultApGl:id,code,name', 'defaultExpenseGl:id,code,name']);

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

    public function create(array $data): Vendor
    {
        return Vendor::create($data);
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        $vendor->update($data);
        return $vendor->fresh();
    }

    public function archive(Vendor $vendor): void
    {
        $openCount = $vendor->invoices()
            ->whereNotIn('status', [VendorInvoiceStatus::Cancelled->value])
            ->count();

        if ($openCount > 0) {
            throw new DomainException(
                "Cannot archive vendor {$vendor->code}: {$openCount} open invoices. Cancel them first."
            );
        }

        $vendor->delete();
    }
}
