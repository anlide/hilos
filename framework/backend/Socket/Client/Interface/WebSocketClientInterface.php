<?php

declare(strict_types=1);

namespace Hilos\Socket\Client\Interface;

use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;

/**
 * WebSocketClientInterface - Interface for WebSocket client implementations.
 *
 * Extends ClientInterface with WebSocket-specific methods.
 */
interface WebSocketClientInterface extends ClientInterface
{
    /** Stop reading input while allowing queued output to be written. */
    public function stopReading(): void;

    /** Close after all queued output has been written. */
    public function closeOnceWritten(): void;

    /**
     * Send WebSocket text frame.
     *
     * @param string $data Text data to send (UTF-8)
     * @throws InvalidStateException When handshake not completed
     * @throws InvalidFormatException When the effective window or a merged envelope is invalid
     */
    public function sendFrame(string $data): void;

    /**
     * Queue immutable application text prepared once per broadcast.
     *
     * @param DeliveryFrame $frame Shared prepared frame
     * @throws InvalidStateException When handshake not completed
     * @throws InvalidFormatException When the effective window or a merged envelope is invalid
     */
    public function sendDeliveryFrame(DeliveryFrame $frame): void;

    /**
     * Send WebSocket binary frame.
     *
     * @param string $data Binary data to send
     */
    public function sendFrameBinary(string $data): void;
}
