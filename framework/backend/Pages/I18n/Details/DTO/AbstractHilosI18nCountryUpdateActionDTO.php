<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** The card and table row share one country's editable fields. */
abstract class AbstractHilosI18nCountryUpdateActionDTO extends ActionPayloadDTO
{
    public const string countryCode = 'countryCode';
    public const string currencySymbol = 'currencySymbol';
    public const string currencyCode = 'currencyCode';
    public const string defaultLocaleCode = 'defaultLocaleCode';

    /**
     * @param string $countryCode Two lowercase Latin letters
     * @param string $currencySymbol Symbol displayed with an amount
     * @param string $currencyCode Currency code, validated by the country write door
     * @param ?string $defaultLocaleCode Locale code, or null to clear the default
     * @throws InvalidFormatException When a country or locale code has an invalid format
     */
    public function __construct(
        public readonly string $countryCode,
        public readonly string $currencySymbol,
        public readonly string $currencyCode,
        public readonly ?string $defaultLocaleCode,
    ) {
        if (preg_match('/^[a-z]{2}$/D', $countryCode) !== 1) {
            throw new InvalidFormatException('Invalid country code: ' . $countryCode);
        }
        if ($defaultLocaleCode !== null && preg_match('/^[a-z]{2}(-[A-Z]{2})?$/D', $defaultLocaleCode) !== 1) {
            throw new InvalidFormatException('Invalid locale code: ' . $defaultLocaleCode);
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
        if (!array_key_exists(self::defaultLocaleCode, $inner)
            || ($inner[self::defaultLocaleCode] !== null && !is_string($inner[self::defaultLocaleCode]))) {
            throw new InvalidFormatException('Payload carries no string or null under key ' . self::defaultLocaleCode);
        }

        return new static(
            self::requireString($inner, self::countryCode),
            self::requireString($inner, self::currencySymbol),
            self::requireString($inner, self::currencyCode),
            $inner[self::defaultLocaleCode],
        );
    }

    /** @return array<string, string|null> Country address, currency and default locale */
    public function toArray(): array
    {
        return [
            self::countryCode => $this->countryCode,
            self::currencySymbol => $this->currencySymbol,
            self::currencyCode => $this->currencyCode,
            self::defaultLocaleCode => $this->defaultLocaleCode,
        ];
    }
}
