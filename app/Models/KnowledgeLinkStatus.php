<?php

namespace App\Models;

enum KnowledgeLinkStatus: string
{
    case Active = 'active';
    case Retracted = 'retracted';
}
