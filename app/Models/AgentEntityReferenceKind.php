<?php

namespace App\Models;

enum AgentEntityReferenceKind: string
{
    case AddressTerm = 'address_term';
    case ContextualName = 'contextual_name';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
