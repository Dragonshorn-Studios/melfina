<?php

namespace App\Models;

enum KnowledgeIdentifierKind: string
{
    case Alias = 'alias';
    case Handle = 'handle';
    case Persona = 'persona';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
