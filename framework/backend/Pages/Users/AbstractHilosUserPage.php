<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Pages\Users\DTO\HilosUserPageSubscribeParams;

/**
 * AbstractHilosUserPage - Abstract base for Hilos single user page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Users\UserPage)
 * by overriding {@see self::onHilosUserSubscribe()}. Parses the `userId` route
 * param into {@see HilosUserPageSubscribeParams} before the hook runs, so
 * subclasses receive a validated, positive integer id.
 */
abstract class AbstractHilosUserPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_USER;

    /**
     * Parses route params into {@see HilosUserPageSubscribeParams} and delegates to
     * {@see self::onHilosUserSubscribe()}. Final: subclasses customize the subscribe
     * behavior by overriding the typed hook, not this method.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params for the page subscription
     * @throws MissingPageRouteParamException When `userId` is absent
     * @throws InvalidPageRouteParamException When `userId` is non-numeric or `<= 0`
     */
    final public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        parent::onSubscribe($acceptKey, $params);
        $this->onHilosUserSubscribe(
            $acceptKey,
            HilosUserPageSubscribeParams::fromPageRouteParams($params),
        );
    }

    /**
     * Optional project-specific subscribe hook running after the projection
     * layer has emitted the initial snapshot. Default is a no-op.
     *
     * @param string $acceptKey WebSocket accept key
     * @param HilosUserPageSubscribeParams $params Parsed subscribe params (always has `userId > 0`)
     */
    protected function onHilosUserSubscribe(string $acceptKey, HilosUserPageSubscribeParams $params): void
    {
    }
}
