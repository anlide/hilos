<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Database\Context\DbContext;

/**
 * Whether anybody navigates to a page, declared by its REACH constant.
 *
 * Interest in a DB collection is taken up when a connection subscribes to a page
 * and let go when it unsubscribes, so the list a page writes in READS_DB only ever
 * means something for a page somebody is on. A page that merely hosts actions
 * arriving while the person looks elsewhere is never the subject of a take-up, and
 * its reads are refused at the moment the button is pressed — which is what the
 * declaration makes sayable and the PAGE-REACH guard makes checkable.
 *
 * Nothing reads this at runtime. It is a declaration a machine judges, the way
 * {@see PageAccessLevel} declares access, and it is inherited: a base answers for
 * its whole branch and thin subclasses stay silent.
 */
enum PageReach: string
{
    /**
     * The root value alone: no page may keep it.
     *
     * {@see AbstractPage} carries it because a real answer there would silently
     * declare every page in the repository and leave the guard nothing to find.
     */
    case UNDECLARED = 'undeclared';

    /** The browser navigates here, so a page subscription takes up what the page reads. */
    case ROUTE = 'route';

    /**
     * Nobody navigates here; the page only hosts actions that arrive while the
     * person is on another page, so its reads belong in
     * {@see DbContext::processWideReadCollections()}.
     */
    case ACTION_HOST = 'action_host';
}
