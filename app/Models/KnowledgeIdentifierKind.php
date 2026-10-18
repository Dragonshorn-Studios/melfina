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

    /**
     * Short owner-console hint shown next to the kind in the picker.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Alias => 'A global nickname anyone can look up. Written into the aliases field. Not a private per-imp title like Szefowa.',
            self::Handle => 'A public or account handle that names this person for the whole install, not one imp.',
            self::Persona => 'A human persona name of this person (Runako). Not the operator persona text, not an imp self-description, and not a scoped title.',
        };
    }
}
