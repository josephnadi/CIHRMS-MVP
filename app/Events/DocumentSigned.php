<?php

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentAnnotation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentSigned
{
    use Dispatchable, SerializesModels;
    public function __construct(public Document $document, public DocumentAnnotation $annotation) {}
}
