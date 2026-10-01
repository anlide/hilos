<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\PageAccountFrozenException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\AccountStandingResolver;

/**
 * Enforces a page's declared ACCESS_LEVEL for one connection.
 *
 * The single carrier of the page access rule, and it has three answers
 * ({@see self::verdict()}): the connection may look and act
 * ({@see PageAccessVerdict::ALLOW}), it may only look - a viewer of the admin view
 * mode on an ADMIN page ({@see PageAccessVerdict::VIEW}) - or it is refused with
 * 401/403, as an exception; a frozen person meets the freeze's own 403 on every
 * page that is not public and not open while frozen ({@see AccountStandingResolver}).
 * Two questions are asked of it.
 *
 * May the connection LOOK ({@see self::assert()}, where VIEW passes): asked from
 * {@see PageSignalRouter::dispatchPageSubscribe} (before onSubscribe, so the page
 * builds no payload for a session that will be refused),
 * {@see PageSignalRouter::dispatchPageUpdateSubscription} (so a subscription
 * cannot be re-pointed by a connection the level denies) and
 * {@see BrowserContext::assertPageGuards} (so the reactive fan-out and the
 * table window re-check the level on every delivery to a subscription kept
 * alive after a denial — the live-promotion model). What a viewer is sent passes
 * the bridge of the view mode on every frame; the gate only lets them in.
 *
 * May the connection ACT ({@see self::assertAction()}, where VIEW passes only
 * the page's {@see AbstractPage::READING_ACTIONS}): asked by the action
 * dispatcher before the handler runs. One carrier keeps the points from
 * drifting apart.
 *
 * Identity resolves through the project browser context
 * ({@see BrowserContext::resolveActionUserId} and
 * {@see BrowserContext::actsAsAdmin} - an administrator's rights carried into a
 * takeover open the same pages, HIL-1170), and whether the connection is a viewer is
 * asked in one place, {@see BrowserContext::isAdminViewModeViewer}. A project
 * without a mounted browser context fails closed: no identity is resolvable, so
 * every non-PUBLIC page denies instead of opening.
 */
final class PageAccessGate
{
    /**
     * Decides what the connection may do on a page of the given class.
     *
     * In this order: a PUBLIC page allows; a viewer of the admin view mode views; an
     * anonymous session is refused 401; a frozen person is refused with the freeze's own
     * 403 unless the page is open while frozen (HIL-945); an ADMIN page refuses a connection that
     * does not act as an admin 403; anything else allows. With the mode off the viewer question answers no without
     * reading anything, and the rest is the path the gate has always taken - a failed admin
     * lookup included. With the mode on a failed lookup makes a viewer, never an admin.
     *
     * The freeze is asked after "who is this" and before "is this an admin", so a frozen
     * administrator hears that they are frozen rather than that they lack a right. The person
     * judged is the one the session acts as - under impersonation the represented person, whose
     * eyes the administrator sees the product through. A viewer of the view mode never gets
     * that far: a viewer changes nothing, and the page is shown to them without an account.
     *
     * @param class-string<AbstractPage> $pageClass Page class declaring ACCESS_LEVEL
     * @param string $acceptKey Acting connection accept key
     * @return PageAccessVerdict Whether the connection may act or only look
     * @throws PageUnauthorizedException When the level requires a user and the session is anonymous (or no browser context is mounted)
     * @throws PageAccountFrozenException When the person is frozen and the page is not open while frozen
     * @throws PageForbiddenException When the level is ADMIN and the authenticated user lacks the admin privilege
     * @throws HilosException When the administrator lookup fails with the admin view mode off, or the person's standing cannot be read
     */
    public static function verdict(string $pageClass, string $acceptKey): PageAccessVerdict
    {
        $level = $pageClass::ACCESS_LEVEL;
        if ($level === PageAccessLevel::PUBLIC) {
            return PageAccessVerdict::ALLOW;
        }

        if (Hilos::$browser?->isAdminViewModeViewer($pageClass, $acceptKey) === true) {
            return PageAccessVerdict::VIEW;
        }

        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            throw new PageUnauthorizedException('Authentication required');
        }

        if (!$pageClass::OPEN_WHILE_FROZEN && AccountStandingResolver::isFrozen($userId)) {
            throw new PageAccountFrozenException();
        }

        if ($level === PageAccessLevel::ADMIN && Hilos::$browser?->actsAsAdmin($acceptKey, $userId) !== true) {
            throw new PageForbiddenException('Access forbidden');
        }

        return PageAccessVerdict::ALLOW;
    }

    /**
     * Asserts the connection may LOOK at a page of the given class - a viewer passes.
     *
     * @param class-string<AbstractPage> $pageClass Page class declaring ACCESS_LEVEL
     * @param string $acceptKey Acting connection accept key
     * @throws PageUnauthorizedException When the level requires a user and the session is anonymous (or no browser context is mounted)
     * @throws PageAccountFrozenException When the person is frozen and the page is not open while frozen
     * @throws PageForbiddenException When the level is ADMIN and the authenticated user lacks the admin privilege
     * @throws HilosException When the administrator lookup fails with the admin view mode off, or the person's standing cannot be read
     */
    public static function assert(string $pageClass, string $acceptKey): void
    {
        self::verdict($pageClass, $acceptKey);
    }

    /**
     * Asserts the connection may run one action of a page of the given class.
     *
     * A viewer runs only what the page declared reading; any other action is refused with
     * the view mode as the reason, before its handler runs.
     *
     * @param class-string<AbstractPage> $pageClass Page class declaring ACCESS_LEVEL and READING_ACTIONS
     * @param string $acceptKey Acting connection accept key
     * @param string $action Name of the action the connection asked for
     * @throws PageUnauthorizedException When the level requires a user and the session is anonymous (or no browser context is mounted)
     * @throws PageAccountFrozenException When the person is frozen and the page is not open while frozen
     * @throws PageForbiddenException When the level is ADMIN and the authenticated user lacks the admin privilege
     * @throws ActionViewModeException When the connection is a viewer and the action is not declared reading
     * @throws HilosException When the administrator lookup fails with the admin view mode off, or the person's standing cannot be read
     */
    public static function assertAction(string $pageClass, string $acceptKey, string $action): void
    {
        if (
            self::verdict($pageClass, $acceptKey) === PageAccessVerdict::VIEW
            && !in_array($action, $pageClass::READING_ACTIONS, true)
        ) {
            throw new ActionViewModeException();
        }
    }

    /**
     * Whether the connection has proved an admin on a page of the given class, now.
     *
     * The one condition under which the text of an exception - its class and message - goes
     * to a connection: an ADMIN page whose gate lets this connection act. Asked at the moment
     * the answer is sent, not remembered from when the action started: a failure can come
     * before the gate ran, and an answer handed over to another process comes back after the
     * rights may have changed. A refusal ({@see PageSubscriptionException}) or a failed lookup
     * proves nothing.
     *
     * @param class-string<AbstractPage> $pageClass Page class declaring ACCESS_LEVEL
     * @param string $acceptKey Connection the answer goes to
     * @return bool Whether the exception's own text may go to this connection
     */
    public static function provesAdmin(string $pageClass, string $acceptKey): bool
    {
        if ($pageClass::ACCESS_LEVEL !== PageAccessLevel::ADMIN) {
            return false;
        }

        try {
            return self::verdict($pageClass, $acceptKey) === PageAccessVerdict::ALLOW;
        } catch (HilosException) {
            return false;
        }
    }
}
