<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AUTHENTICATED browser guard: an anonymous session is denied
 * a guarded page with a 401, and any authenticated user passes. Unlike the ACCESS
 * guard the guard carries no source or flag — it only asks the project hook
 * (resolveCurrentUserId) whether the accept key resolves to a user.
 */
final class BrowserContextAuthenticatedGuardTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAuthenticatedGuardDeniesAGuest(): void
    {
        Hilos::$sr = new SignalRouter();
        $context = new AuthenticatedGuardTestBrowserContext(null);

        $this->expectException(PageUnauthorizedException::class);
        $context->subscribeSnapshot(
            AuthenticatedGuardTestBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
    }

    public function testAuthenticatedGuardAllowsAnyUser(): void
    {
        Hilos::$sr = new SignalRouter();
        $context = new AuthenticatedGuardTestBrowserContext(5);

        // The guard passes, so subscribeSnapshot runs to completion without
        // throwing; the page has no table bindings, so it queues nothing.
        $context->subscribeSnapshot(
            AuthenticatedGuardTestBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }
}

final class AuthenticatedGuardTestBrowserContext extends BrowserContext
{
    public const string PAGE = 'authenticated_guard_page';
    public const string SIGNAL = 'authenticated_guard_signal';

    public function __construct(private readonly ?int $currentUserId)
    {
        parent::__construct();
    }

    /**
     * Returns the injected current user id, standing in for the project's
     * acceptKey -> user mapping.
     *
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ?int Injected current user id
     */
    protected function resolveCurrentUserId(string $acceptKey): ?int
    {
        return $this->currentUserId;
    }

    /**
     * Resolves the authenticated-guarded test page config.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Guarded page metadata, or null when absent
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
            BrowserConfigKey::GUARDS => [
                [
                    BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED,
                ],
            ],
        ]);
    }

    /**
     * The guarded test page has no table bindings.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Empty bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::empty();
    }
}
