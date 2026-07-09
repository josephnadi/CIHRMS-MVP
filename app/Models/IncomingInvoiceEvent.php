<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingInvoiceEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'incoming_invoice_id', 'actor_id', 'action', 'from_status', 'to_status', 'comment', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
