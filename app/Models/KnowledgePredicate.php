<?php

namespace App\Models;

/**
 * Closed set of edge types. Agents pick from this list — free-text
 * predicates would collapse the graph back into "related". Inverse names
 * (`hosts`, `owns`, …) are accepted on write and stored as the canonical
 * left-hand predicate with endpoints swapped.
 */
enum KnowledgePredicate: string
{
    case RelatedTo = 'related_to';
    case DependsOn = 'depends_on';
    case Supports = 'supports';
    case PartOf = 'part_of';
    case HasPart = 'has_part';
    case RunsOn = 'runs_on';
    case Hosts = 'hosts';
    case OwnedBy = 'owned_by';
    case Owns = 'owns';
    case Uses = 'uses';
    case UsedBy = 'used_by';
    case DecidedIn = 'decided_in';
    case Decides = 'decides';
    case Involves = 'involves';
    case InvolvedIn = 'involved_in';
    case AuthoredBy = 'authored_by';
    case Authored = 'authored';
    case Contradicts = 'contradicts';
    case Supersedes = 'supersedes';
    case SupersededBy = 'superseded_by';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The predicate stored on the row. Inverse names fold into this.
     */
    public function stored(): self
    {
        return match ($this) {
            self::Supports => self::DependsOn,
            self::HasPart => self::PartOf,
            self::Hosts => self::RunsOn,
            self::Owns => self::OwnedBy,
            self::UsedBy => self::Uses,
            self::Decides => self::DecidedIn,
            self::InvolvedIn => self::Involves,
            self::Authored => self::AuthoredBy,
            self::SupersededBy => self::Supersedes,
            default => $this,
        };
    }

    /**
     * True when writing this name should swap from/to before storing.
     */
    public function swapsEndpoints(): bool
    {
        return $this !== $this->stored();
    }

    public function inverse(): self
    {
        return match ($this) {
            self::RelatedTo, self::Contradicts => $this,
            self::DependsOn => self::Supports,
            self::Supports => self::DependsOn,
            self::PartOf => self::HasPart,
            self::HasPart => self::PartOf,
            self::RunsOn => self::Hosts,
            self::Hosts => self::RunsOn,
            self::OwnedBy => self::Owns,
            self::Owns => self::OwnedBy,
            self::Uses => self::UsedBy,
            self::UsedBy => self::Uses,
            self::DecidedIn => self::Decides,
            self::Decides => self::DecidedIn,
            self::Involves => self::InvolvedIn,
            self::InvolvedIn => self::Involves,
            self::AuthoredBy => self::Authored,
            self::Authored => self::AuthoredBy,
            self::Supersedes => self::SupersededBy,
            self::SupersededBy => self::Supersedes,
        };
    }
}
