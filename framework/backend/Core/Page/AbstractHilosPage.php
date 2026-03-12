<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\SignalTypeConstants;
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
}
