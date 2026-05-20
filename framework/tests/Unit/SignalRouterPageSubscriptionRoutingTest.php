<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests page subscription routing from project topology.
 */
final class SignalRouterPageSubscriptionRoutingTest extends TestCase
{
    public function testPageSubscribeRoutesThroughProjectTopology(): void
    {
        $this->assertSame(
            [
                [
                    'type' => 'agent',
                    'agentType' => SignalRouterTopologyTestPage::SUBSCRIPTION_AGENT_TYPE,
                    'agentIndex' => null,
                ],
            ],
            (new SignalRouterTopologyTestRouter())->getDestinations(
                $this->pageSubscribeSignal(SignalRouterTopologyTestPage::PAGE),
            ),
        );
    }

    public function testUnregisteredPageUsesProjectFallback(): void
    {
        $this->assertSame(
            [
                [
                    'type' => 'agent',
                    'agentType' => SignalRouterTopologyFallbackTestRouter::FALLBACK_AGENT_TYPE,
                    'agentIndex' => null,
                ],
            ],
            (new SignalRouterTopologyFallbackTestRouter())->getDestinations(
                $this->pageSubscribeSignal('unregistered_page'),
            ),
        );
    }

    public function testBaseRouterDoesNotRouteUnregisteredPageWithoutFallback(): void
    {
        $this->assertSame(
            [],
            (new SignalRouter())->getDestinations($this->pageSubscribeSignal('unregistered_page')),
        );
    }

    /**
     * Creates a page subscribe signal for routing tests.
     *
     * @param string $page Page name
     * @return SignalDTO Subscribe signal
     */
    private function pageSubscribeSignal(string $page): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($page),
            new WebSocketPageSubscribeSignalDTO('accept-key', $page),
        );
    }
}

final class SignalRouterTopologyTestRouter extends SignalRouter
{
    /**
     * Returns fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return SignalRouterTopologyTestHilos::class;
    }
}

final class SignalRouterTopologyFallbackTestRouter extends SignalRouter
{
    public const string FALLBACK_AGENT_TYPE = 'fallback_agent';

    /**
     * Returns fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return SignalRouterTopologyTestHilos::class;
    }

    /**
     * Returns fixture fallback agent for unregistered pages.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultPageSubscriptionAgentType(): ?string
    {
        return self::FALLBACK_AGENT_TYPE;
    }
}

final class SignalRouterTopologyTestPage extends AbstractPage
{
    public const string PAGE = 'topology_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'topology_agent';
}

final class SignalRouterTopologyTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for router topology tests.
     */
    public function configure(): void
    {
    }
}

final class SignalRouterTopologyTestHilos extends HilosFacade
{
    public const array PAGES = [
        SignalRouterTopologyTestPage::PAGE => SignalRouterTopologyTestPage::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new SignalRouterTopologyTestDbContext();
    }
}
