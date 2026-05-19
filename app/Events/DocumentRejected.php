<?php

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentRoute;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentRejected
{
    use Dispatchable;
    public function __construct(public Document $document, public DocumentRoute $route) {}
}
