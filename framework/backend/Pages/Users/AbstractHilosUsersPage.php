<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;

/**
 * Base class for the framework Hilos users-list page.
 *
 * Subscribe behavior is supplied by project browser configs or page overrides.
 * The base page key is shared with the browser context so projects can return
 * page-shaped users snapshots through the default subscription handler.
 *
 * The list owns no action of its own. Taking a person over stood here as its one row action
 * until HIL-1170 moved the button onto the person's card ({@see AbstractHilosUserPage}), where
 * the other operations on the person already were.
 */
abstract class AbstractHilosUsersPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_USERS;

    public const PageReach REACH = PageReach::ROUTE;

    /**
     * Lets go of the connection's place among the lists kept in step.
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        AccountStandingAudience::removeSubscriber($acceptKey);
    }

    /**
     * Holds the connection among the lists kept in step with who is past a deadline (HIL-945).
     *
     * A window narrowed to the people past one document's deadline is sent again when they change
     * ({@see AccountStandingAudience}); an unnarrowed one follows the person table as it did.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params (unused)
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        AccountStandingAudience::addListSubscriber($acceptKey);
    }
}
