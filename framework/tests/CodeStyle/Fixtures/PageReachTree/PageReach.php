<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\PageReachTree;

/**
 * Stand-in for the production enum, so the toy tree is self-consistent PHP. The rule
 * reads the raw value text of a constant and never loads either enum.
 */
enum PageReach: string
{
    case UNDECLARED = 'undeclared';

    case ROUTE = 'route';

    case ACTION_HOST = 'action_host';
}
