<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Fixtures\Extension;

use Hilos\Database\Object\Collection\VerifierCircleMembers;

/**
 * The object collection of the test chain, re-pointed at the project's Object and Entity
 * collection. COLLECTION_KEY stays the framework's: the chain is mounted under the framework's
 * key, and process-to-process sync finds it there.
 */
class NotedMemberObjects extends VerifierCircleMembers
{
    public const string OBJECT_CLASS = NotedMemberObject::class;
    public const string ENTITY_COLLECTION_CLASS = NotedMemberEntities::class;
}
