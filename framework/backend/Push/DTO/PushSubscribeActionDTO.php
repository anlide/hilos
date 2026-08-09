<?php

declare(strict_types=1);

namespace Hilos\Push\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Push\PushSubscriptionAction;

/**
 * PushSubscribeActionDTO - payload for the push_subscribe action (HIL-199).
 *
 * Carries the browser PushSubscription the acting device produced: its endpoint URL
 * and the two encryption keys (p256dh public key and auth secret, both base64url) the
 * delivery agent needs to encrypt a payload for it, plus the device user agent for the
 * admin subscription view. The acting user is resolved server-side from the
 * connection, never carried here, so a client can only subscribe its own device.
 */
final class PushSubscribeActionDTO extends ActionPayloadDTO
{
    /** Payload key: the browser push endpoint URL. */
    public const string endpoint = 'endpoint';

    /** Payload key: the client public key (base64url). */
    public const string p256dh = 'p256dh';

    /** Payload key: the client auth secret (base64url). */
    public const string auth = 'auth';

    /** Payload key: the subscribing device user agent. */
    public const string userAgent = 'userAgent';

    /**
     * @param string $endpoint Browser push endpoint URL
     * @param string $p256dh Client public key (base64url)
     * @param string $auth Client auth secret (base64url)
     * @param ?string $userAgent Subscribing device user agent, or null
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $p256dh,
        public readonly string $auth,
        public readonly ?string $userAgent,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return PushSubscriptionAction::SUBSCRIBE;
    }

    /**
     * Whether the payload carries an endpoint and both encryption keys.
     *
     * @return bool True when the subscription is fully described
     */
    public function isValid(): bool
    {
        return $this->endpoint !== '' && $this->p256dh !== '' && $this->auth !== '';
    }

    /**
     * @return array<string, mixed> Data with the endpoint, keys, and device user agent
     */
    public function toArray(): array
    {
        return [
            self::endpoint => $this->endpoint,
            self::p256dh => $this->p256dh,
            self::auth => $this->auth,
            self::userAgent => $this->userAgent,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        $userAgent = $inner[self::userAgent] ?? null;

        return new static(
            endpoint: self::requireString($inner, self::endpoint),
            p256dh: self::requireString($inner, self::p256dh),
            auth: self::requireString($inner, self::auth),
            userAgent: $userAgent === null ? null : (string)$userAgent,
        );
    }
}
