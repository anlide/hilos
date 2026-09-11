<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\PageReachTree;

/**
 * Nobody navigates here, and it still names collections of both kinds — the second
 * finding, once per list it fills.
 */
final class ActionHostPage extends AbstractPage
{
    public const PageReach REACH = PageReach::ACTION_HOST;

    public const array READS_DB = ['notifications'];

    public const array READS_RT = ['clusterNodes'];
}
