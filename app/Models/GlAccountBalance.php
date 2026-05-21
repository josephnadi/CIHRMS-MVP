<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlAccountBalance extends Model
{
    protected $table = 'gl_account_balances';
    protected $primaryKey = 'gl_account_id';
    public $incrementing = false;
    protected $keyType = 'int';
    public const CREATED_AT = null;
    // updated_at is present; Eloquent default UPDATED_AT works.

    protected $fillable = ['gl_account_id', 'balance', 'last_posted_at'];

    protected function casts(): array
    {
        return [
            'balance'        => 'decimal:2',
            'last_posted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
