<?php

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentAnnotation;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentSigned
{
    use Dispatchable;
    public function __construct(public Document $document, public DocumentAnnotation $annotation) {}
}
