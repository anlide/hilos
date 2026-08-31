<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\PageReachTree;

/**
 * The base of the toy hierarchy: carries the root value and must stay silent for it.
 */
abstract class AbstractPage
{
    public const PageReach REACH = PageReach::UNDECLARED;

    public const array READS_DB = [];
}
