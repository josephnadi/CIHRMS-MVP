<?php

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentRoute;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentRouted
{
    use Dispatchable;
    public function __construct(public Document $document, public DocumentRoute $route) {}
}
