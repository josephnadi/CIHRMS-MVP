<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingAccount extends Model
{
    protected $table = 'posting_accounts';

    protected $fillable = ['slug', 'gl_account_id', 'domain', 'description', 'locked'];

    protected function casts(): array
    {
        return ['locked' => 'bool'];
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
