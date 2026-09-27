<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Fixtures\Extension;

use Hilos\Database\Entity\Collection\VerifierCircleMembers;

/**
 * The Entity collection of the test chain, re-pointed at the project's Entity.
 */
class NotedMemberEntities extends VerifierCircleMembers
{
    public const string ENTITY_CLASS = NotedMemberEntity::class;
}
