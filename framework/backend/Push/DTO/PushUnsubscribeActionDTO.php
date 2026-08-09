<?php

declare(strict_types=1);

namespace Hilos\Push\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Push\PushSubscriptionAction;

/**
 * PushUnsubscribeActionDTO - payload for the push_unsubscribe action (HIL-199).
 *
 * Carries the endpoint of the browser PushSubscription the acting device tore down.
 * The row is deleted by endpoint (endpoints are globally unique); the acting user is
 * resolved server-side from the connection, never carried here.
 */
final class PushUnsubscribeActionDTO extends ActionPayloadDTO
{
    /** Payload key: the browser push endpoint URL. */
    public const string endpoint = 'endpoint';

    /**
     * @param string $endpoint Browser push endpoint URL
     */
    public function __construct(
        public readonly string $endpoint,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return PushSubscriptionAction::UNSUBSCRIBE;
    }

    /**
     * Whether the payload carries a non-empty endpoint.
     *
     * @return bool True when the endpoint is present
     */
    public function isValid(): bool
    {
        return $this->endpoint !== '';
    }

    /**
     * @return array<string, mixed> Data with the endpoint
     */
    public function toArray(): array
    {
        return [
            self::endpoint => $this->endpoint,
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

        return new static(
            endpoint: self::requireString($inner, self::endpoint),
        );
    }
}
