<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Request to delete one country by its persisted ID. */
final class HilosI18nCountryDeleteActionDTO extends ActionPayloadDTO
{
    /** Payload key: country ID. */
    public const string countryId = 'countryId';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $countryId Persisted country ID
     * @throws InvalidFormatException When the country ID has an invalid format
     */
    public function __construct(public readonly int $countryId)
    {
        if ($countryId <= 0) {
            throw new InvalidFormatException('Invalid country ID: ' . $countryId);
        }
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_COUNTRY_DELETE;
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed deletion request
     * @throws InvalidFormatException When countryId is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(self::requireInt($inner, self::countryId));
    }

    /** @return array<string, int> Country address */
    public function toArray(): array
    {
        return [self::countryId => $this->countryId];
    }
}
