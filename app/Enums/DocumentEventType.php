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

    // Documents v2 — Phase 1 additions.
    case Updated      = 'updated';
    case Deleted      = 'deleted';
    case Shared       = 'shared';
    case Unshared     = 'unshared';

    // Documents v2 — Phase 2 additions.
    case AnnotationMoved   = 'annotation_moved';
    case AnnotationResized = 'annotation_resized';
}
