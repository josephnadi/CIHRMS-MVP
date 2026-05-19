<?php

namespace App\Enums;

enum DocumentAnnotationType: string
{
    case Signature = 'signature';
    case Stamp     = 'stamp';
    case Text      = 'text';
    case Initial   = 'initial';
    case Highlight = 'highlight';
}
