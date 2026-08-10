<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageServiceUnavailableException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the protected-mode route lockdown enforced by the browser context
 * as defense-in-depth behind the master welcome choke. While the freeze is up every
 * connection but the initiator's is refused all page data with a 503 domain sentence,
 * regardless of the page's own guards (the lockdown is binary). The check reads the
 * daemon-owned runtime singleton synced into the worker and is inert when no project
 * mounted it.
 */
final class BrowserContextProtectedModeLockdownTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testFrozenNonInitiatorIsRefusedWithADomainMessage(): void
    {
        Hilos::$sr = new SignalRouter();
        $this->seed(ProtectedModeRuntime::PHASE_ACTIVATING, 'ak-initiator');
        $context = new ProtectedModeLockdownTestBrowserContext();

        try {
            $context->subscribeSnapshot(
                ProtectedModeLockdownTestBrowserContext::PAGE,
                'ak-other',
                new PageRouteParams([]),
            );
            $this->fail('Expected the frozen connection to be refused.');
        } catch (PageServiceUnavailableException $e) {
            $this->assertSame(503, $e->httpCode);
            $this->assertSame('service_unavailable', $e->errorCode);
            $this->assertStringNotContainsString('\\', $e->getMessage());
        }
    }

    public function testInitiatorPassesTheLockdown(): void
    {
        Hilos::$sr = new SignalRouter();
        $this->seed(ProtectedModeRuntime::PHASE_ACTIVE, 'ak-initiator');
        $context = new ProtectedModeLockdownTestBrowserContext();

        // The initiator holds the recorded key, so the lockdown lets it through; the
        // guard-less page then queues nothing.
        $context->subscribeSnapshot(
            ProtectedModeLockdownTestBrowserContext::PAGE,
            'ak-initiator',
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testInactiveModeLocksNobodyOut(): void
    {
        Hilos::$sr = new SignalRouter();
        $this->seed(ProtectedModeRuntime::PHASE_INACTIVE, null);
        $context = new ProtectedModeLockdownTestBrowserContext();

        $context->subscribeSnapshot(
            ProtectedModeLockdownTestBrowserContext::PAGE,
            'ak-other',
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testUnmountedRuntimeIsInert(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        $context = new ProtectedModeLockdownTestBrowserContext();

        $context->subscribeSnapshot(
            ProtectedModeLockdownTestBrowserContext::PAGE,
            'ak-other',
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Mounts the protected-mode runtime singleton in the desired freeze state.
     *
     * @param string $phase Freeze phase to seed
     * @param ?string $initiatorAcceptKey Recorded initiator accept key, or null
     */
    private function seed(string $phase, ?string $initiatorAcceptKey): void
    {
        $state = ProtectedModeRuntime::create();
        $state->phase = $phase;
        $state->initiatorAcceptKey = $initiatorAcceptKey;

        Hilos::$rt = new ProtectedModeLockdownTestRtContext($state);
        Hilos::$rt->configure();
    }
}

final class ProtectedModeLockdownTestRtContext extends RtContext
{
    public function __construct(private readonly ProtectedModeRuntime $state)
    {
        parent::__construct();
    }

    /**
     * Mounts the injected protected-mode runtime singleton flat, as ClusterRtContext does.
     */
    public function configure(): void
    {
        $this->_stateItems[ProtectedModeRuntime::RT_ITEM] = $this->state;
    }
}

final class ProtectedModeLockdownTestBrowserContext extends BrowserContext
{
    public const string PAGE = 'protected_mode_lockdown_page';
    public const string SIGNAL = 'protected_mode_lockdown_signal';

    /**
     * Resolves a guard-less test page: the lockdown must freeze it regardless.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Guard-less page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
        ]);
    }

    /**
     * The test page has no table bindings.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Empty bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::empty();
    }
}
