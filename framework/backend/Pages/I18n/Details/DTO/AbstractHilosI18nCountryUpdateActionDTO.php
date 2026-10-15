<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** The card and table row share one country's editable fields. */
abstract class AbstractHilosI18nCountryUpdateActionDTO extends ActionPayloadDTO
{
    public const string countryId = 'countryId';
    public const string currencySymbol = 'currencySymbol';
    public const string currencyCode = 'currencyCode';
    public const string defaultLocaleId = 'defaultLocaleId';

    /**
     * @param int $countryId Persisted country ID
     * @param string $currencySymbol Symbol displayed with an amount
     * @param string $currencyCode Currency code, validated by the country write door
     * @param ?int $defaultLocaleId Persisted locale ID, or null to clear the default
     * @throws InvalidFormatException When a country or locale ID has an invalid format
     */
    public function __construct(
        public readonly int $countryId,
        public readonly string $currencySymbol,
        public readonly string $currencyCode,
        public readonly ?int $defaultLocaleId,
    ) {
        if ($countryId <= 0) {
            throw new InvalidFormatException('Invalid country ID: ' . $countryId);
        }
        if ($defaultLocaleId !== null && $defaultLocaleId <= 0) {
            throw new InvalidFormatException('Invalid locale ID: ' . $defaultLocaleId);
        }
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed country update request
     * @throws InvalidFormatException When a required field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }
        if (!array_key_exists(self::defaultLocaleId, $inner)
            || ($inner[self::defaultLocaleId] !== null && !is_int($inner[self::defaultLocaleId]))) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . self::defaultLocaleId);
        }

        return new static(
            self::requireInt($inner, self::countryId),
            self::requireString($inner, self::currencySymbol),
            self::requireString($inner, self::currencyCode),
            $inner[self::defaultLocaleId],
        );
    }

    /** @return array<string, int|string|null> Country address, currency and default locale */
    public function toArray(): array
    {
        return [
            self::countryId => $this->countryId,
            self::currencySymbol => $this->currencySymbol,
            self::currencyCode => $this->currencyCode,
            self::defaultLocaleId => $this->defaultLocaleId,
        ];
    }
}
