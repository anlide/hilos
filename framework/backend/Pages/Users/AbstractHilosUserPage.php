<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\HilosException;
use Hilos\Pages\Users\DTO\HilosUserPageSubscribeParams;

/**
 * Base class for the framework Hilos single-user page.
 *
 * The default subscription path answers the client, parses the `userId` route
 * param, and then calls {@see self::onHilosUserSubscribe()}.
 */
abstract class AbstractHilosUserPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_USER;

    public const PageReach REACH = PageReach::ROUTE;

    /**
     * Parses route params and runs the typed hook, once the client has been answered.
     *
     * Final: subclasses customize subscribe behavior through
     * {@see self::onHilosUserSubscribe()}, not this method.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params for the page subscription
     * @throws MissingPageRouteParamException When `userId` is absent
     * @throws InvalidPageRouteParamException When `userId` is non-numeric or `<= 0`
     * @throws HilosException Whatever else the typed hook raises
     */
    final protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->onHilosUserSubscribe(
            $acceptKey,
            HilosUserPageSubscribeParams::fromPageRouteParams($params),
        );
    }

    /**
     * Runs optional project-specific subscribe behavior after the page has been answered.
     *
     * Default intentionally does nothing.
     *
     * @param string $acceptKey WebSocket accept key
     * @param HilosUserPageSubscribeParams $params Parsed subscribe params (always has `userId > 0`)
     */
    protected function onHilosUserSubscribe(string $acceptKey, HilosUserPageSubscribeParams $params): void
    {
    }
}
