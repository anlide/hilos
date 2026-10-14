<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** A literal language name or locale correction to add. */
final class HilosI18nLanguageNameAddActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    public const string languageCode = 'languageCode';
    public const string inLanguageCode = 'inLanguageCode';
    public const string localeCode = 'localeCode';
    public const string name = 'name';

    /**
     * @param string $languageCode Named language code
     * @param string $inLanguageCode Writing language code
     * @param ?string $localeCode Country locale of the writing language, or null for the base
     * @param string $name Literal name, including empty text and whitespace
     * @throws InvalidFormatException When coordinates violate the wire contract
     */
    public function __construct(
        public readonly string $languageCode,
        public readonly string $inLanguageCode,
        public readonly ?string $localeCode,
        public readonly string $name,
    ) {
        if (preg_match('/^[a-z]{2}$/D', $languageCode) !== 1) {
            throw new InvalidFormatException('Invalid language code: ' . $languageCode);
        }
        if (preg_match('/^[a-z]{2}$/D', $inLanguageCode) !== 1 || $languageCode === $inLanguageCode) {
            throw new InvalidFormatException('The writing language must be another two-letter language code.');
        }
        if ($localeCode !== null
            && (preg_match('/^[a-z]{2}-[A-Z]{2}$/D', $localeCode) !== 1 || !str_starts_with($localeCode, $inLanguageCode . '-'))) {
            throw new InvalidFormatException('The correction must name a country locale of the writing language.');
        }
    }

    /** @return string Tracked add action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_ADD;
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed name add request
     * @throws InvalidFormatException When a required field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = array_key_exists(SignalPayloadConstants::FIELD_DATA, $data)
            ? self::requireArray($data, SignalPayloadConstants::FIELD_DATA)
            : $data;

        return new static(
            self::requireString($inner, self::languageCode),
            self::requireString($inner, self::inLanguageCode),
            self::optionalString($inner, self::localeCode),
            self::requireString($inner, self::name),
        );
    }

    /** @return array<string, ?string> Coordinates and literal name text */
    public function toArray(): array
    {
        return [
            self::languageCode => $this->languageCode,
            self::inLanguageCode => $this->inLanguageCode,
            self::localeCode => $this->localeCode,
            self::name => $this->name,
        ];
    }
}
