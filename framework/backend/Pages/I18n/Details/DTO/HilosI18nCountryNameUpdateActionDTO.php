<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;

/** A literal country name or locale correction to update. */
final class HilosI18nCountryNameUpdateActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    public const string countryCode = 'countryCode';
    public const string inLanguageCode = 'inLanguageCode';
    public const string localeCode = 'localeCode';
    public const string name = 'name';
    public const string expectedName = 'expectedName';

    /**
     * @param string $countryCode Named country code
     * @param string $inLanguageCode Writing language code
     * @param ?string $localeCode Country locale of the writing language, or null for the base
     * @param string $name Literal name, including empty text and whitespace
     * @param string $expectedName Latest live text acknowledged by the edit session
     * @throws InvalidFormatException When coordinates or expected text violate the wire contract
     */
    public function __construct(
        public readonly string $countryCode,
        public readonly string $inLanguageCode,
        public readonly ?string $localeCode,
        public readonly string $name,
        public readonly string $expectedName,
    ) {
        if (preg_match('/^[a-z]{2}$/D', $countryCode) !== 1) {
            throw new InvalidFormatException('Invalid country code: ' . $countryCode);
        }
        if (preg_match('/^[a-z]{2}$/D', $inLanguageCode) !== 1) {
            throw new InvalidFormatException('The writing language must be a two-letter language code.');
        }
        if ($localeCode !== null
            && (preg_match('/^[a-z]{2}-[A-Z]{2}$/D', $localeCode) !== 1 || !str_starts_with($localeCode, $inLanguageCode . '-'))) {
            throw new InvalidFormatException('The correction must name a country locale of the writing language.');
        }
        if (mb_strlen($expectedName) > EntityCountryName::NAME_MAX_CHARS) {
            throw new InvalidFormatException('The expected name exceeds its column width.');
        }
    }

    /** @return string Tracked update action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_UPDATE;
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed name update request
     * @throws InvalidFormatException When a required field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = array_key_exists(SignalPayloadConstants::FIELD_DATA, $data)
            ? self::requireArray($data, SignalPayloadConstants::FIELD_DATA)
            : $data;

        return new static(
            self::requireString($inner, self::countryCode),
            self::requireString($inner, self::inLanguageCode),
            self::optionalString($inner, self::localeCode),
            self::requireString($inner, self::name),
            self::requireString($inner, self::expectedName),
        );
    }

    /** @return array<string, ?string> Coordinates and literal name text */
    public function toArray(): array
    {
        return [
            self::countryCode => $this->countryCode,
            self::inLanguageCode => $this->inLanguageCode,
            self::localeCode => $this->localeCode,
            self::name => $this->name,
            self::expectedName => $this->expectedName,
        ];
    }
}
