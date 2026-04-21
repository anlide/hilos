<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;

/**
 * AbstractHilosPage - Abstract base class for Hilos admin page handlers.
 *
 * Base class for all framework-level Hilos admin pages.
 * Projects can extend these pages via inheritance.
 */
abstract class AbstractHilosPage extends AbstractPage
{
    // TODO: [change-log] Before each DB write that should attribute hilos_change_log rows, set MySQL session
    // variable (e.g. SET @hilos_user_id = <userId>;) so triggers can read the acting user. Wire this in the
    // database layer or connection wrapper used for authenticated requests.
    /**
     * Send signal to a specific user (WebSocket connection by acceptKey).
     *
     * Uses agent's signal source for routing context without depending on agent's concrete type.
     *
     * @param string $signalName Signal name
     * @param string $acceptKey Target connection acceptKey
     * @param SignalDataInterface $data Signal payload
     */
    protected function sendToUser(string $signalName, string $acceptKey, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
        );
    }

    /**
     * Send signal to all users (broadcast). Optionally exclude one connection.
     *
     * Uses agent's signal source for routing context without depending on agent's concrete type.
     *
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Signal payload
     * @param ?string $excludeAcceptKey Optional acceptKey to exclude from delivery
     */
    protected function sendToAllUsers(string $signalName, SignalDataInterface $data, ?string $excludeAcceptKey = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, excludeAcceptKey: $excludeAcceptKey),
        );
    }

    /**
     * Emit a DB-layer change; the daemon signal mapper expands it to WebSocket deliveries.
     *
     * Uses agent's signal source for routing context without depending on agent's concrete type.
     *
     * @param string $eventKey Logical event name for the project mapper
     * @param EmitDbChangeSignalData $data DB change payload
     */
    protected function emitChangeDb(string $eventKey, EmitDbChangeSignalData $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::EMIT_DB_CHANGE),
            signalName: new SignalName($eventKey),
            signalData: $data,
        );
    }
}
