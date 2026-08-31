<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\PageReachTree;

/**
 * A base that answers for its branch and reads a collection of its own.
 */
abstract class AbstractDeclaredBase extends AbstractPage
{
    public const PageReach REACH = PageReach::ROUTE;

    public const array READS_DB = ['rooms'];
}
