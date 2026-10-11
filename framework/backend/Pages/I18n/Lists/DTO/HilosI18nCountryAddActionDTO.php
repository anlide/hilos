<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Request to add one country with its currency. */
final class HilosI18nCountryAddActionDTO extends ActionPayloadDTO
{
    public const string countryCode = 'countryCode';
    public const string currencySymbol = 'currencySymbol';
    public const string currencyCode = 'currencyCode';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $countryCode Two lowercase Latin letters
     * @param string $currencySymbol Symbol displayed with an amount
     * @param string $currencyCode Three uppercase ISO 4217 letters
     * @throws InvalidFormatException When the country code has an invalid format
     */
    public function __construct(
        public readonly string $countryCode,
        public readonly string $currencySymbol,
        public readonly string $currencyCode,
    ) {
        if (preg_match('/^[a-z]{2}$/D', $countryCode) !== 1) {
            throw new InvalidFormatException('Invalid country code: ' . $countryCode);
        }
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_COUNTRY_ADD;
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed country add request
     * @throws InvalidFormatException When a required field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            self::requireString($inner, self::countryCode),
            self::requireString($inner, self::currencySymbol),
            self::requireString($inner, self::currencyCode),
        );
    }

    /** @return array<string, string> Country code and currency */
    public function toArray(): array
    {
        return [
            self::countryCode => $this->countryCode,
            self::currencySymbol => $this->currencySymbol,
            self::currencyCode => $this->currencyCode,
        ];
    }
}
