<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AssetAudit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetAuditOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AssetAudit $audit)
    {
    }
}
