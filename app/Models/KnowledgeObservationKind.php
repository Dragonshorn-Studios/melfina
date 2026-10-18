<?php

namespace App\Models;

enum KnowledgeObservationKind: string
{
    case EntityUpsert = 'entity_upsert';
    case LinkAssert = 'link_assert';
    case LinkRetract = 'link_retract';
    case LinkFix = 'link_fix';
    case OwnerConfirm = 'owner_confirm';
    case OwnerPermanent = 'owner_permanent';
    case OwnerUnpermanent = 'owner_unpermanent';
}
