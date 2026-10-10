<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\I18n\DTO\LocaleFormats;

/** Request to update one locale addressed by its language and optional country. */
final class HilosI18nLocaleUpdateActionDTO extends ActionPayloadDTO
{
    public const string languageCode = 'languageCode';
    public const string countryCode = 'countryCode';
    public const string formats = 'formats';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $languageCode Two lowercase Latin letters
     * @param ?string $countryCode Two lowercase Latin letters, or null for the language alone
     * @param LocaleFormats $formats Seven display formats
     * @throws InvalidFormatException When either code has an invalid format
     */
    public function __construct(
        public readonly string $languageCode,
        public readonly ?string $countryCode,
        public readonly LocaleFormats $formats,
    ) {
        if (preg_match('/^[a-z]{2}$/D', $languageCode) !== 1) {
            throw new InvalidFormatException('Invalid language code: ' . $languageCode);
        }
        if ($countryCode !== null && preg_match('/^[a-z]{2}$/D', $countryCode) !== 1) {
            throw new InvalidFormatException('Invalid country code: ' . $countryCode);
        }
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE;
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed locale update request
     * @throws InvalidFormatException When a code or the formats are absent or malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        if (!array_key_exists(self::countryCode, $inner)
            || ($inner[self::countryCode] !== null && !is_string($inner[self::countryCode]))) {
            throw new InvalidFormatException('Payload carries no string or null under key ' . self::countryCode);
        }

        return new static(
            self::requireString($inner, self::languageCode),
            $inner[self::countryCode],
            LocaleFormats::fromArray(self::requireArray($inner, self::formats)),
        );
    }

    /** @return array<string, mixed> Locale address and formats */
    public function toArray(): array
    {
        return [
            self::languageCode => $this->languageCode,
            self::countryCode => $this->countryCode,
            self::formats => $this->formats->toArray(),
        ];
    }
}
