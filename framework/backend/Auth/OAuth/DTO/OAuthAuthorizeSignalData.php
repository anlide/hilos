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
 */
final class OAuthAuthorizeSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    /**
     * @param string $acceptKey Initiating connection accept key the signal targets
     * @param string $authorizeUrl Absolute provider authorization URL to navigate to
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $authorizeUrl,
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
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no accept key or no authorize URL
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            authorizeUrl: self::requireString($data, 'authorizeUrl'),
        );
    }
}
