<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;

/**
 * What the page access gate lets one connection do on a page it did not refuse.
 *
 * Two of the gate's three answers. The third, the refusal, stays what it has always been - an
 * exception ({@see PageUnauthorizedException} 401, {@see PageForbiddenException} 403) - because a
 * refusal already has its wire, its client reaction and its tests, and a third answer is added
 * without rewriting the two old ones. See docs/agents/architecture/admin-view-mode.md,
 * "The View Verdict".
 */
enum PageAccessVerdict: string
{
    /** The connection may look at the page and act on it. */
    case ALLOW = 'allow';

    /** The connection may look at an admin page in the admin view mode and run only its reading actions. */
    case VIEW = 'view';
}
