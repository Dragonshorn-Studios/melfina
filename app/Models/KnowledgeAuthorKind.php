<?php

namespace App\Models;

enum KnowledgeAuthorKind: string
{
    case Agent = 'agent';
    case Owner = 'owner';
}
