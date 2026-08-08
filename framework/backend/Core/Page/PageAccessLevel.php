<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

/**
 * Access level a page class declares through its ACCESS_LEVEL constant.
 *
 * Names the three states the access machinery already knows how to serve: no
 * identity check at all, a resolvable authenticated user, or the project's
 * admin privilege on top of it. The constant is inherited, so the framework
 * admin surface ({@see AbstractHilosPage}) closes every page by default and
 * openness becomes an explicit declaration on the page class — a mounting
 * project cannot forget the rule, because the rule travels with the page.
 */
enum PageAccessLevel: string
{
    /** No identity requirement: the page is readable by an anonymous session. */
    case PUBLIC = 'public';

    /** Requires a resolvable authenticated user behind the connection. */
    case AUTHENTICATED = 'authenticated';

    /** Requires an authenticated user holding the project's admin privilege. */
    case ADMIN = 'admin';
}
