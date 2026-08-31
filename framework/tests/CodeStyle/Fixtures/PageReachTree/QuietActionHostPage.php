<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\PageReachTree;

/**
 * An action host that leans on nothing — the legal shape of the second finding.
 */
final class QuietActionHostPage extends AbstractPage
{
    public const PageReach REACH = PageReach::ACTION_HOST;
}
