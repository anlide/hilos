<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Liveness;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;

/**
 * The four spans of a browser socket's liveness, read once and fixed for the socket's life.
 *
 * Every span counts silence from the last frame the browser sent: the first RFC ping goes out
 * after the idle span, the next ones every interval while the silence lasts, the socket is
 * probably offline from the third span and closed at the fourth. The spans are independent
 * deadlines from the same moment, so the close is not the sum of the ones before it.
 *
 * A combination that cannot keep that order is refused where it is read, before the socket it
 * was read for is served. A check that switched itself off, or refused again on every tick,
 * would hide the same mistake.
 */
final readonly class WebSocketLivenessConfig
{
    /**
     * @param int $pingIdleMs Silence before the first ping
     * @param int $pingIntervalMs Spacing of the pings that follow while the silence lasts
     * @param int $probablyOfflineMs Silence from which the socket counts as probably offline
     * @param int $closeTimeoutMs Silence at which the socket is closed
     * @throws EnvInvalidValueException When a span is not positive or the three deadlines are out of order
     */
    private function __construct(
        public int $pingIdleMs,
        public int $pingIntervalMs,
        public int $probablyOfflineMs,
        public int $closeTimeoutMs,
    ) {
        if ($pingIdleMs <= 0 || $pingIntervalMs <= 0) {
            throw new EnvInvalidValueException(sprintf(
                "Environment variables '%s' and '%s' must be positive; got %d and %d",
                EnvConstants::WEBSOCKET_PING_IDLE_MS->name,
                EnvConstants::WEBSOCKET_PING_INTERVAL_MS->name,
                $pingIdleMs,
                $pingIntervalMs,
            ));
        }
        if ($pingIdleMs >= $probablyOfflineMs || $probablyOfflineMs >= $closeTimeoutMs) {
            throw new EnvInvalidValueException(sprintf(
                "Environment variables '%s' < '%s' < '%s' must hold; got %d, %d and %d",
                EnvConstants::WEBSOCKET_PING_IDLE_MS->name,
                EnvConstants::WEBSOCKET_PROBABLY_OFFLINE_MS->name,
                EnvConstants::WEBSOCKET_CLOSE_TIMEOUT_MS->name,
                $pingIdleMs,
                $probablyOfflineMs,
                $closeTimeoutMs,
            ));
        }
    }

    /**
     * Reads the four spans through the environment accessor.
     *
     * @return self Spans for one socket, checked as a whole
     * @throws EnvException When a key is absent from the catalog or its value is not an integer
     * @throws EnvInvalidValueException When a span is not positive or the three deadlines are out of order
     */
    public static function fromEnv(): self
    {
        return new self(
            pingIdleMs: Hilos::$env[EnvConstants::WEBSOCKET_PING_IDLE_MS]->int(),
            pingIntervalMs: Hilos::$env[EnvConstants::WEBSOCKET_PING_INTERVAL_MS]->int(),
            probablyOfflineMs: Hilos::$env[EnvConstants::WEBSOCKET_PROBABLY_OFFLINE_MS]->int(),
            closeTimeoutMs: Hilos::$env[EnvConstants::WEBSOCKET_CLOSE_TIMEOUT_MS]->int(),
        );
    }
}
