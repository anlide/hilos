<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** The person's explicit time zone choice; the owner validates the exact IANA name. */
final class TimeZonePickActionDTO extends ActionPayloadDTO
{
    public const string timeZone = 'timeZone';

    public const array SECRET_FIELDS = [];

    /** @param ?string $timeZone Exact IANA name, or null for Automatic */
    public function __construct(public readonly ?string $timeZone)
    {
    }

    /** @return string Browser action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_TIME_ZONE_PICK;
    }

    /**
     * @param array<string, mixed> $data Browser payload; identity is resolved on the server
     * @return static Explicit time zone choice
     * @throws InvalidFormatException When the choice is missing or is neither a string nor null
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireNullableString($data, self::timeZone));
    }

    /** @return array{timeZone: ?string} Transport payload */
    public function toArray(): array
    {
        return [self::timeZone => $this->timeZone];
    }
}
