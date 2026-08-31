<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\PageReachTree;

/**
 * An action host whose reads come from its base: the list is inherited, and so is the
 * refusal it earns.
 */
final class InheritedReadsPage extends AbstractDeclaredBase
{
    public const PageReach REACH = PageReach::ACTION_HOST;
}
