<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Fixtures\Extension;

use Hilos\Database\View\Collection\VerifierCircleMembers;

/**
 * The view collection of the test chain, re-pointed at the project's item and object collection.
 * This is the class a project names in its FrameworkExtension: the rest of the chain is reached
 * from here.
 */
class NotedMembers extends VerifierCircleMembers
{
    public const string DB_ITEM_CLASS = NotedMemberItem::class;
    public const string OBJECT_COLLECTION_CLASS = NotedMemberObjects::class;
}
