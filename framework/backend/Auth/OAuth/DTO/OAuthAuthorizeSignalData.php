<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;

/**
 * OAuthAuthorizeSignalData - the OAuth start reply carrying the authorize URL (HIL-281).
 *
 * The `oauthStart` page action mints the provider authorize URL synchronously (no
 * outbound HTTP), but the framework's `action_success` carries no domain payload, so
 * it cannot hand the URL to the browser. This signal is delivered WS_USER to the
 * initiating connection's accept key instead; the SPA callback surface navigates the
 * browser to {@see authorizeUrl} on receipt (the "client action = loading + signal,
 * never fire-forget" pattern applied to the redirect start).
 *
 * It also carries the trip the start named back to the browser (HIL-707): the
 * accept key addresses the connection, not the attempt, so without {@see tripId}
 * a late answer to an abandoned trip steers the window of the current one. The
 * {@see provider} rides along for the log line naming whose tail was dropped —
 * the window knows its own trip, not the one it just refused.
 */
final class OAuthAuthorizeSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    /**
     * @param string $acceptKey Initiating connection accept key the signal targets
     * @param string $authorizeUrl Absolute provider authorization URL to navigate to
     * @param string $tripId The trip id the start named, echoed back untouched
     * @param string $provider Provider key the start named, e.g. 'oauth:github'
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $authorizeUrl,
        public readonly string $tripId,
        public readonly string $provider,
    ) {
    }

    /**
     * @return string Accept key the authorize signal is delivered to
     */
    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, string> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'authorizeUrl' => $this->authorizeUrl,
            'tripId' => $this->tripId,
            'provider' => $this->provider,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no accept key, authorize URL, trip id or provider
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            authorizeUrl: self::requireString($data, 'authorizeUrl'),
            tripId: self::requireString($data, 'tripId'),
            provider: self::requireString($data, 'provider'),
        );
    }
}
