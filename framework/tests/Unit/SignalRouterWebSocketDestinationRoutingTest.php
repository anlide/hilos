<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\Destination\AllClientsDestination;
use Hilos\Core\Router\Destination\SessionClientsDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Tests WebSocket destination routing for the two fan-out markers a signal can resolve to.
 *
 * Both say "the node holding the connections decides who gets this" rather than naming one:
 * the broadcast reaches everybody it holds, and the session marker reaches the connections of
 * one browser, which is a set no address could have spelled - a reloaded tab has an accept key
 * nobody upstream has ever seen.
 */
final class SignalRouterWebSocketDestinationRoutingTest extends TestCase
{
    public function testBroadcastResolvesToSingleAllClientsDestinationExcludingSender(): void
    {
        $this->assertEquals(
            [
                new AllClientsDestination('sender-key'),
            ],
            new SignalRouter()->getDestinations($this->broadcastSignal('sender-key')),
        );
    }

    public function testBroadcastWithoutExcludeKeyTargetsEveryConnection(): void
    {
        $this->assertEquals(
            [
                new AllClientsDestination(null),
            ],
            new SignalRouter()->getDestinations($this->broadcastSignal(null)),
        );
    }

    public function testASessionSignalResolvesToOneSessionFanoutMarker(): void
    {
        $this->assertEquals(
            [
                new SessionClientsDestination('session-hash-9'),
            ],
            new SignalRouter()->getDestinations($this->sessionSignal('session-hash-9')),
        );
    }

    public function testAnEmptySessionHashAddressesNobodyRatherThanEveryone(): void
    {
        // The one way this marker could go wrong: an unaddressed fan-out over every connection
        // of the node, carrying a frame meant for one browser's own operation.
        $this->assertSame([], new SignalRouter()->getDestinations($this->sessionSignal('')));
    }

    public function testAnEmptyTargetIsFoldedIntoNullOnConstruction(): void
    {
        $data = new WebSocketSignalData(
            data: new SignalData(),
            targetAcceptKey: '',
            targetSessionTokenHash: '',
            targetGroup: '',
            excludeAcceptKey: '',
        );

        $this->assertNull($data->targetAcceptKey);
        $this->assertNull($data->targetSessionTokenHash);
        $this->assertNull($data->targetGroup);
        $this->assertNull($data->excludeAcceptKey);
    }

    public function testAnEmptyTargetAcceptKeyAddressesNobodyRatherThanEveryone(): void
    {
        $this->assertSame(
            [],
            new SignalRouter()->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::AGENT),
                new SignalType(SignalTypeConstants::WS_USER),
                new SignalName('user_signal'),
                new WebSocketSignalData(data: new SignalData(), targetAcceptKey: ''),
            )),
        );
    }

    public function testAnEmptyExcludeKeyBroadcastsToEveryConnection(): void
    {
        $this->assertEquals(
            [
                new AllClientsDestination(null),
            ],
            new SignalRouter()->getDestinations($this->broadcastSignal('')),
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

    /**
     * Builds a ws_session signal as emitted by sendToSession().
     *
     * @param string $targetSessionTokenHash Hash of the session whose connections receive it
     * @return SignalDTO Session-addressed signal
     */
    private function sessionSignal(string $targetSessionTokenHash): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_SESSION),
            new SignalName('session_signal'),
            new WebSocketSignalData(data: new SignalData(), targetSessionTokenHash: $targetSessionTokenHash),
        );
    }
}
