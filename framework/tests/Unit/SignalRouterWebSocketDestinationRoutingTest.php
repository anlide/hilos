<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\Destination\AllClientsDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Tests WebSocket destination routing for the all-connected broadcast signal.
 */
final class SignalRouterWebSocketDestinationRoutingTest extends TestCase
{
    public function testBroadcastResolvesToSingleAllClientsDestinationExcludingSender(): void
    {
        $this->assertEquals(
            [
                new AllClientsDestination('sender-key'),
            ],
            (new SignalRouter())->getDestinations($this->broadcastSignal('sender-key')),
        );
    }

    public function testBroadcastWithoutExcludeKeyTargetsEveryConnection(): void
    {
        $this->assertEquals(
            [
                new AllClientsDestination(null),
            ],
            (new SignalRouter())->getDestinations($this->broadcastSignal(null)),
        );
    }

    /**
     * Builds a ws_all_connected broadcast signal as emitted by sendToAllConnected().
     *
     * @param ?string $excludeAcceptKey Accept key to exclude, or null to send to all
     * @return SignalDTO Broadcast signal
     */
    private function broadcastSignal(?string $excludeAcceptKey): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            new SignalName('broadcast_signal'),
            new WebSocketSignalData(data: new SignalData(), excludeAcceptKey: $excludeAcceptKey),
        );
    }
}
