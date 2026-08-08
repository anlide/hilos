<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Hilos;

/**
 * Enforces a page's declared ACCESS_LEVEL for one connection.
 *
 * The single carrier of the page access rule, called from both enforcement
 * points: {@see PageSignalRouter::dispatchPageSubscribe} (before onSubscribe,
 * so no page payload leaves before the check) and
 * {@see BrowserContext::assertPageGuards} (so the reactive fan-out and the
 * table window re-check the level on every delivery to a subscription kept
 * alive after a denial — the live-promotion model). One carrier keeps the two
 * points from drifting apart.
 *
 * Identity resolves through the project browser context
 * ({@see BrowserContext::resolveActionUserId} and
 * {@see BrowserContext::isAdmin}). A project without a mounted browser context
 * fails closed: no identity is resolvable, so every non-PUBLIC page denies
 * instead of opening.
 */
final class PageAccessGate
{
    /**
     * Asserts the acting connection may access a page of the given class.
     *
     * @param class-string<AbstractPage> $pageClass Page class declaring ACCESS_LEVEL
     * @param string $acceptKey Acting connection accept key
     * @throws PageUnauthorizedException When the level requires a user and the session is anonymous (or no browser context is mounted)
     * @throws PageForbiddenException When the level is ADMIN and the authenticated user lacks the admin privilege
     */
    public static function assert(string $pageClass, string $acceptKey): void
    {
        $level = $pageClass::ACCESS_LEVEL;
        if ($level === PageAccessLevel::PUBLIC) {
            return;
        }

        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            throw new PageUnauthorizedException('Authentication required');
        }

        if ($level === PageAccessLevel::ADMIN && Hilos::$browser?->isAdmin($userId) !== true) {
            throw new PageForbiddenException('Access forbidden');
        }
    }
}
