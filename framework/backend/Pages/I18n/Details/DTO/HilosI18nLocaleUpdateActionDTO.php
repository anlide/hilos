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
    public const string languageId = 'languageId';
    public const string countryId = 'countryId';
    public const string localeId = 'localeId';
    public const string formats = 'formats';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $languageId Persisted language ID
     * @param ?int $countryId Persisted country ID, or null for the language alone
     * @param int $localeId Persisted locale ID
     * @param LocaleFormats $formats Seven display formats
     * @throws InvalidFormatException When a parent ID is not positive
     */
    public function __construct(
        public readonly int $languageId,
        public readonly ?int $countryId,
        public readonly int $localeId,
        public readonly LocaleFormats $formats,
    ) {
        if ($localeId <= 0) {
            throw new InvalidFormatException('Invalid locale ID: ' . $localeId);
        }
        if ($languageId <= 0) {
            throw new InvalidFormatException('Invalid language ID: ' . $languageId);
        }
        if ($countryId !== null && $countryId <= 0) {
            throw new InvalidFormatException('Invalid country ID: ' . $countryId);
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
     * @throws InvalidFormatException When an ID or the formats are absent or malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        if (!array_key_exists(self::countryId, $inner)
            || ($inner[self::countryId] !== null && !is_int($inner[self::countryId]))) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . self::countryId);
        }

        return new static(
            self::requireInt($inner, self::languageId),
            $inner[self::countryId],
            self::requireInt($inner, self::localeId),
            LocaleFormats::fromArray(self::requireArray($inner, self::formats)),
        );
    }

    /** @return array<string, mixed> Locale address and formats */
    public function toArray(): array
    {
        return [
            self::languageId => $this->languageId,
            self::countryId => $this->countryId,
            self::localeId => $this->localeId,
            self::formats => $this->formats->toArray(),
        ];
    }
}
