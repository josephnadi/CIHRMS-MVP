<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    protected $table = 'sync_state';
    protected $fillable = ['feed', 'watermark', 'last_cursor', 'last_run_at'];
    protected function casts(): array { return ['last_run_at' => 'datetime']; }

    public static function for(string $feed): self
    {
        return static::firstOrCreate(['feed' => $feed]);
    }
}
