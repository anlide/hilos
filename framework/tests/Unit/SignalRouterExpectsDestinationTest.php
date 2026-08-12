<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use PHPUnit\Framework\TestCase;

/**
 * Which signal types are supposed to reach somebody (HIL-567).
 *
 * This is the whole of the decision behind the daemon's lost-signal line, kept in the router
 * next to the routing tables and asserted here without a daemon. The split it draws: a type
 * routed from a declared registry has a missing route when its destination list comes back
 * empty, while a type routed from live state — who is subscribed, who is connected — is
 * merely idle, and calling that a loss would bury the real ones under it.
 */
final class SignalRouterExpectsDestinationTest extends TestCase
{
    public function testRegistryRoutedTypesExpectADestination(): void
    {
        $router = new SignalRouter();

        foreach ([
            SignalTypeConstants::AGENT_SIGNAL,
            SignalTypeConstants::ACTION,
            SignalTypeConstants::COMMAND_REQUEST,
        ] as $signalType) {
            $this->assertTrue(
                $router->expectsDestination($this->signal($signalType)),
                "Signal type {$signalType} takes its route from a registry and cannot go nowhere",
            );
        }
    }

    public function testStateRoutedTypesDoNotExpectADestination(): void
    {
        $router = new SignalRouter();

        foreach ([
            SignalTypeConstants::WS_USER,
            SignalTypeConstants::WS_ALL,
            SignalTypeConstants::WS_ALL_CONNECTED,
            SignalTypeConstants::WS_GROUP,
            SignalTypeConstants::DB_SYNC_CREATED,
            SignalTypeConstants::DB_SYNC_UPDATED,
            SignalTypeConstants::DB_SYNC_DELETED,
            SignalTypeConstants::DB_SYNC_CLEARED,
            SignalTypeConstants::RT_SYNC_CREATED,
            SignalTypeConstants::RT_SYNC_UPDATED,
            SignalTypeConstants::RT_SYNC_DELETED,
            SignalTypeConstants::SYSTEM,
            SignalTypeConstants::HANDSHAKE,
            SignalTypeConstants::CONNECTION_CLOSE,
        ] as $signalType) {
            $this->assertFalse(
                $router->expectsDestination($this->signal($signalType)),
                "Signal type {$signalType} is routed by live state and is empty in the ordinary case",
            );
        }
    }

    /**
     * The source is not part of the answer: a signal that was supposed to reach somebody is
     * lost whichever of our processes queued it.
     */
    public function testTheSourceDoesNotChangeTheAnswer(): void
    {
        $router = new SignalRouter();

        foreach ([SignalSource::AGENT, SignalSource::WORKER, SignalSource::DAEMON] as $source) {
            $this->assertTrue($router->expectsDestination(new SignalDTO(
                new SignalSource($source),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName('some_signal'),
                new AgentSignalData(new SignalData([])),
            )));
        }
    }

    /**
     * Builds a signal of the given type, with the parts the answer does not read left plain.
     *
     * @param string $signalType Signal type under test
     */
    private function signal(string $signalType): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType($signalType),
            new SignalName('some_signal'),
            new SignalData([]),
        );
    }
}
