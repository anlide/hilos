<?php

declare(strict_types=1);

namespace Hilos\Push\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Push delivery agent → notifications library: endpoints the service reported gone.
 */
final class PushSubscriptionsGoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string endpoints = 'endpoints';

    /** @param list<string> $endpoints Gone push endpoint URLs */
    public function __construct(public readonly array $endpoints)
    {
    }

    /** @return array{endpoints: list<string>} Gone endpoint payload */
    public function toArray(): array
    {
        return [self::endpoints => $this->endpoints];
    }

    /**
     * @param array<string, mixed> $data Source payload
     * @return static Parsed gone-endpoint frame
     * @throws InvalidFormatException When the endpoint list is absent or contains a non-string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::optionalStringList($data, self::endpoints)
                ?? throw new InvalidFormatException('Payload carries no string list under key ' . self::endpoints),
        );
    }
}
