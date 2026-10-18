<?php

namespace App\Models;

enum KnowledgeIdentityStatus: string
{
    case Resolved = 'resolved';
    case NotFound = 'not_found';
    case Ambiguous = 'ambiguous';
    case Inconsistent = 'inconsistent';
    case Conflict = 'conflict';
    case Closed = 'closed';
}
