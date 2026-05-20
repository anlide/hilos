<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
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
        $destinations = (new SignalRouterServiceDefaultsTestRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(SignalConstants::INITIAL_AGENTS_START),
            new SystemSignalDTO(SignalConstants::INITIAL_AGENTS_START),
        ));

        $this->assertSame([
            ['type' => 'agent', 'agentType' => 'bootstrap_alpha', 'agentIndex' => null],
            ['type' => 'agent', 'agentType' => 'bootstrap_beta', 'agentIndex' => null],
        ], $destinations);
    }

    public function testGenericDaemonCronRoutesThroughDefaultHook(): void
    {
        $destinations = (new SignalRouterServiceDefaultsTestRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::CRON),
            new SignalName('generic_cron'),
            new CronSignalDTO('generic_cron'),
        ));

        $this->assertSame([
            ['type' => 'agent', 'agentType' => 'cron_agent', 'agentIndex' => null],
        ], $destinations);
    }

    public function testWebSocketLifecycleRoutesThroughDefaultHook(): void
    {
        $router = new SignalRouterServiceDefaultsTestRouter();

        $this->assertSame([
            ['type' => 'agent', 'agentType' => 'lifecycle_agent', 'agentIndex' => null],
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

        $this->assertSame([
            ['type' => 'agent', 'agentType' => 'lifecycle_agent', 'agentIndex' => null],
        ], $router->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName('accept-key'),
            new WebSocketCloseSignalDTO('accept-key'),
        )));

        $this->assertSame([
            ['type' => 'agent', 'agentType' => 'lifecycle_agent', 'agentIndex' => null],
        ], $router->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CRON),
            new SignalName('ws_cron'),
            new CronSignalDTO('ws_cron'),
        )));
    }

    public function testExplicitConfigOverridesServiceDefaults(): void
    {
        $destinations = (new SignalRouterServiceDefaultsOverrideTestRouter())->getDestinations(new SignalDTO(
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
        ));

        $this->assertSame([
            ['type' => 'agent', 'agentType' => 'override_agent', 'agentIndex' => null],
        ], $destinations);
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

final class SignalRouterServiceDefaultsOverrideTestRouter extends SignalRouter
{
    public function __construct()
    {
        parent::__construct();

        $this->config = [
            'signals' => [
                SignalSource::WEBSOCKET => [
                    SignalTypeConstants::HANDSHAKE => 'override_agent',
                ],
            ],
        ];
    }

    /**
     * @return ?string
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return 'lifecycle_agent';
    }
}
