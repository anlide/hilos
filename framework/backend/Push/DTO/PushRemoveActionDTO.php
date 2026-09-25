<?php

declare(strict_types=1);

namespace Hilos\Push\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Push\PushSubscriptionAction;

/**
 * Names one push-subscription device row for removal by its owner.
 */
final class PushRemoveActionDTO extends ActionPayloadDTO
{
    public const string subscriptionId = 'subscriptionId';

    /** @param int $subscriptionId Positive push-subscription row id */
    public function __construct(public readonly int $subscriptionId)
    {
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return PushSubscriptionAction::REMOVE;
    }

    /**
     * @param array<string, mixed> $data Raw payload, optionally wrapped in the action-data envelope
     * @return static Parsed remove request
     * @throws InvalidFormatException When the row id is absent, mistyped or not positive
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        $subscriptionId = self::requireInt($inner, self::subscriptionId);
        if ($subscriptionId <= 0) {
            throw new InvalidFormatException('Push subscription id must be positive');
        }

        return new static($subscriptionId);
    }

    /** @return array{subscriptionId: int} Subscription row id */
    public function toArray(): array
    {
        return [self::subscriptionId => $this->subscriptionId];
    }
}
