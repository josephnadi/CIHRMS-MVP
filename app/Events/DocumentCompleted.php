<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentCompleted
{
    use Dispatchable;
    public function __construct(public Document $document) {}
}
