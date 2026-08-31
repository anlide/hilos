<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\PageReachTree;

/**
 * A root that answers for the whole tree at once — the third finding.
 */
abstract class AbstractLoudRoot extends AbstractPage
{
    public const PageReach REACH = PageReach::ROUTE;
}
