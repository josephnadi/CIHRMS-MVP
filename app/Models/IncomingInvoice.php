<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncomingInvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomingInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'status', 'department_id', 'vendor_name', 'vendor_invoice_no',
        'invoice_date', 'currency', 'amount', 'description',
        'submitted_by', 'submitted_at', 'vetted_by', 'vetted_at', 'vetting_notes',
        'approved_by', 'approved_at', 'returned_by', 'returned_at', 'return_reason',
        'posted_by', 'posted_at', 'vendor_invoice_id', 'created_by',
    ];

    protected $attributes = ['status' => 'draft', 'currency' => 'GHS', 'amount' => 0];

    protected function casts(): array
    {
        return [
            'status'       => IncomingInvoiceStatus::class,
            'invoice_date' => 'date',
            'amount'       => 'decimal:2',
            'submitted_at' => 'datetime',
            'vetted_at'    => 'datetime',
            'approved_at'  => 'datetime',
            'returned_at'  => 'datetime',
            'posted_at'    => 'datetime',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(IncomingInvoiceAttachment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncomingInvoiceEvent::class)->orderByDesc('id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vendorInvoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class);
    }
}
