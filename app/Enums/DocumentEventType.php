<?php

namespace App\Enums;

enum DocumentEventType: string
{
    case Uploaded     = 'uploaded';
    case VersionAdded = 'version_added';
    case Routed       = 'routed';
    case Annotated    = 'annotated';
    case Signed       = 'signed';
    case Stamped      = 'stamped';
    case Forwarded    = 'forwarded';
    case Rejected     = 'rejected';
    case Completed    = 'completed';
    case Withdrawn    = 'withdrawn';
    case Downloaded   = 'downloaded';
    case Archived     = 'archived';
}
