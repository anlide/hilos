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

    public const string countryId = 'countryId';
    public const string inLanguageId = 'inLanguageId';
    public const string localeId = 'localeId';
    public const string nameId = 'nameId';
    public const string name = 'name';
    public const string expectedName = 'expectedName';

    /**
     * @param int $countryId Named country ID
     * @param int $inLanguageId Writing language ID
     * @param ?int $localeId Country locale of the writing language, or null for the base
     * @param int $nameId Captured primary ID of the name to change
     * @param string $name Literal name, including empty text and whitespace
     * @param string $expectedName Latest live text acknowledged by the edit session
     * @throws InvalidFormatException When coordinates or expected text violate the wire contract
     */
    public function __construct(
        public readonly int $countryId,
        public readonly int $inLanguageId,
        public readonly ?int $localeId,
        public readonly int $nameId,
        public readonly string $name,
        public readonly string $expectedName,
    ) {
        if ($countryId <= 0) {
            throw new InvalidFormatException('Invalid countryId: ' . $countryId);
        }
        if ($inLanguageId <= 0) {
            throw new InvalidFormatException('Invalid inLanguageId: ' . $inLanguageId);
        }
        if ($localeId !== null && $localeId <= 0) {
            throw new InvalidFormatException('Invalid localeId: ' . $localeId);
        }
        if ($nameId <= 0) {
            throw new InvalidFormatException('Invalid nameId: ' . $nameId);
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

        if (!array_key_exists(self::localeId, $inner)
            || ($inner[self::localeId] !== null && !is_int($inner[self::localeId]))) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . self::localeId);
        }

        return new static(
            self::requireInt($inner, self::countryId),
            self::requireInt($inner, self::inLanguageId),
            $inner[self::localeId],
            self::requireInt($inner, self::nameId),
            self::requireString($inner, self::name),
            self::requireString($inner, self::expectedName),
        );
    }

    /** @return array<string, int|string|null> Coordinates and literal name text */
    public function toArray(): array
    {
        return [
            self::countryId => $this->countryId,
            self::inLanguageId => $this->inLanguageId,
            self::localeId => $this->localeId,
            self::nameId => $this->nameId,
            self::name => $this->name,
            self::expectedName => $this->expectedName,
        ];
    }
}
