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
     * Owner-facing name of this kind, distinct from the stored token.
     */
    public function label(): string
    {
        return match ($this) {
            self::Alias => 'another name for this same entity',
            self::Handle => 'account name stored on this same entity',
            self::Persona => 'string name stored on this same entity',
        };
    }

    /**
     * Closed-control label in the kind picker.
     */
    public function pickerLabel(): string
    {
        return $this->value.' — '.$this->label();
    }

    /**
     * Hint for a stored identifier kind. Alias is the kind owners and
     * agents add. Handle and persona strings are older rows that still
     * resolve to this same entity. A different presentation of a person
     * is a persona link, not one of these strings.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Alias => 'Another name for this same entity. Lookup returns this entry.',
            self::Handle => 'An account name already stored on this entity. Lookup returns this entry.',
            self::Persona => 'A name string already stored on this entity. Lookup returns this entry. A different presentation is a persona link to another person.',
        };
    }
}
