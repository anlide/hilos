<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\VerifierCircleMember as EntityVerifierCircleMember;
use IteratorAggregate;

/**
 * VerifierCircleMembers - Entity collection for the framework hilos_verifier_circle table.
 *
 * @extends EntityCollection<EntityVerifierCircleMember>
 * @implements IteratorAggregate<int|string, EntityVerifierCircleMember>
 * @implements ArrayAccess<int|string, EntityVerifierCircleMember>
 */
final class VerifierCircleMembers extends EntityCollection
{
    public const string ENTITY_CLASS = EntityVerifierCircleMember::class;
}
