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

    public const string languageId = 'languageId';
    public const string inLanguageId = 'inLanguageId';
    public const string localeId = 'localeId';
    public const string baseNameId = 'baseNameId';
    public const string name = 'name';

    /**
     * @param int $languageId Named language ID
     * @param int $inLanguageId Writing language ID
     * @param ?int $localeId Country locale of the writing language, or null for the base
     * @param ?int $baseNameId Captured primary ID of the base name, null for a new base
     * @param string $name Literal name, including empty text and whitespace
     * @throws InvalidFormatException When coordinates violate the wire contract
     */
    public function __construct(
        public readonly int $languageId,
        public readonly int $inLanguageId,
        public readonly ?int $localeId,
        public readonly ?int $baseNameId,
        public readonly string $name,
    ) {
        if ($languageId <= 0) {
            throw new InvalidFormatException('Invalid languageId: ' . $languageId);
        }
        if ($inLanguageId <= 0) {
            throw new InvalidFormatException('Invalid inLanguageId: ' . $inLanguageId);
        }
        if ($localeId !== null && $localeId <= 0) {
            throw new InvalidFormatException('Invalid localeId: ' . $localeId);
        }
        if ($baseNameId !== null && $baseNameId <= 0) {
            throw new InvalidFormatException('Invalid baseNameId: ' . $baseNameId);
        }
        if ($languageId === $inLanguageId) {
            throw new InvalidFormatException('The writing language must be another language.');
        }
        if (($localeId === null) !== ($baseNameId === null)) {
            throw new InvalidFormatException('A correction requires both localeId and baseNameId.');
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

        if (!array_key_exists(self::localeId, $inner)
            || ($inner[self::localeId] !== null && !is_int($inner[self::localeId]))) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . self::localeId);
        }
        if (!array_key_exists(self::baseNameId, $inner)
            || ($inner[self::baseNameId] !== null && !is_int($inner[self::baseNameId]))) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . self::baseNameId);
        }

        return new static(
            self::requireInt($inner, self::languageId),
            self::requireInt($inner, self::inLanguageId),
            $inner[self::localeId],
            $inner[self::baseNameId],
            self::requireString($inner, self::name),
        );
    }

    /** @return array<string, int|string|null> Coordinates and literal name text */
    public function toArray(): array
    {
        return [
            self::languageId => $this->languageId,
            self::inLanguageId => $this->inLanguageId,
            self::localeId => $this->localeId,
            self::baseNameId => $this->baseNameId,
            self::name => $this->name,
        ];
    }
}
