<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketLivenessSignalDTO;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests service-signal default routing hooks on SignalRouter.
 */
final class SignalRouterServiceSignalDefaultsTest extends TestCase
{
    public function testSystemBootstrapRoutesThroughDefaultHook(): void
    {
        $destinations = new SignalRouterServiceDefaultsTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(SignalConstants::INITIAL_AGENTS_START),
            new SystemSignalDTO(SignalConstants::INITIAL_AGENTS_START),
        ));

        $this->assertEquals([
            new AgentDestination('bootstrap_alpha'),
            new AgentDestination('bootstrap_beta'),
        ], $destinations);
    }

    public function testGenericDaemonCronRoutesThroughDefaultHook(): void
    {
        $destinations = new SignalRouterServiceDefaultsTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::CRON),
            new SignalName('generic_cron'),
            new CronSignalDTO('generic_cron'),
        ));

        $this->assertEquals([
            new AgentDestination('cron_agent'),
        ], $destinations);
    }

    public function testWebSocketLifecycleRoutesThroughDefaultHook(): void
    {
        $router = new SignalRouterServiceDefaultsTestRouter();

        $this->assertEquals([
            new AgentDestination('lifecycle_agent'),
        ], $router->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::HANDSHAKE),
            new SignalName(SignalTypeConstants::HANDSHAKE),
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'accept-key',
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
        )));

        $this->assertEquals([
            new AgentDestination('lifecycle_agent'),
        ], $router->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
            new WebSocketCloseSignalDTO('accept-key'),
        )));

        $this->assertEquals([
            new AgentDestination('lifecycle_agent'),
        ], $router->getDestinations(self::livenessSignal(SignalSource::WEBSOCKET)));

        $this->assertEquals([
            new AgentDestination('lifecycle_agent'),
        ], $router->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CRON),
            new SignalName('ws_cron'),
            new CronSignalDTO('ws_cron'),
        )));
    }

    /**
     * A registered sessions library takes the handshake and nothing else of the lifecycle: the
     * liveness transition writes the connection row, which stays the lifecycle owner's (HIL-1566).
     */
    public function testASessionsLibraryTakesTheHandshakeButNotTheLivenessTransition(): void
    {
        $router = new SignalRouterServiceDefaultsSessionsTestRouter();

        $this->assertEquals([
            new AgentDestination(HilosAgentType::HILOS_SESSIONS_LIBRARY),
        ], $router->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::HANDSHAKE),
            new SignalName(SignalTypeConstants::HANDSHAKE),
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'accept-key',
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
        )));

        $this->assertEquals([
            new AgentDestination('lifecycle_agent'),
        ], $router->getDestinations(self::livenessSignal(SignalSource::WEBSOCKET)));
    }

    /**
     * The transition is the master socket's word about one of its connections: queued from any
     * other source, the same type and payload are owned by no service route.
     */
    public function testTheLivenessTransitionIsRoutedOnlyFromTheWebSocketSource(): void
    {
        $router = new SignalRouterServiceDefaultsTestRouter();

        foreach ([SignalSource::AGENT, SignalSource::WORKER, SignalSource::DAEMON] as $source) {
            $this->assertSame(
                [],
                $router->getDestinations(self::livenessSignal($source)),
                "A liveness transition queued from {$source} reaches nobody",
            );
        }
    }

    /**
     * Builds the liveness transition of one connection as queued from the given source.
     *
     * @param string $source Signal source identifier
     * @return SignalDTO Transition signal named after its own type
     */
    private static function livenessSignal(string $source): SignalDTO
    {
        return new SignalDTO(
            new SignalSource($source),
            new SignalType(SignalTypeConstants::CONNECTION_LIVENESS),
            new SignalName(SignalTypeConstants::CONNECTION_LIVENESS),
            new WebSocketLivenessSignalDTO('accept-key', true),
        );
    }
}

final class SignalRouterServiceDefaultsTestRouter extends SignalRouter
{
    /**
     * @return list<string>
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return ['bootstrap_alpha', 'bootstrap_beta'];
    }

    /**
     * @return ?string
     */
    protected function getDefaultDaemonCronAgentType(): ?string
    {
        return 'cron_agent';
    }

    /**
     * @return ?string
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return 'lifecycle_agent';
    }
}

/**
 * Lifecycle router of a project that also registers the sessions library.
 */
final class SignalRouterServiceDefaultsSessionsTestRouter extends SignalRouter
{
    protected function hilosClass(): string
    {
        return SignalRouterServiceDefaultsSessionsTestHilos::class;
    }

    /**
     * @return ?string
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return 'lifecycle_agent';
    }
}

/**
 * Project facade fixture registering the sessions library.
 *
 * Abstract because only its registry constant is read.
 */
abstract class SignalRouterServiceDefaultsSessionsTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => AbstractSessionsLibraryAgent::class],
    ];
}
