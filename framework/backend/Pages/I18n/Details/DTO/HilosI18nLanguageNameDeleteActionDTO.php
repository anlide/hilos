<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;

/** A confirmed language name base removal with the literal text the reader saw. */
final class HilosI18nLanguageNameDeleteActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    public const string languageId = 'languageId';
    public const string inLanguageId = 'inLanguageId';
    public const string nameId = 'nameId';
    public const string expectedName = 'expectedName';

    /**
     * @param int $languageId Named language ID
     * @param int $inLanguageId Writing language ID
     * @param int $nameId Captured primary ID of the name to change
     * @param string $expectedName Literal text seen when confirmation opened, including empty text
     * @throws InvalidFormatException When coordinates or the expected name violate the wire contract
     */
    public function __construct(
        public readonly int $languageId,
        public readonly int $inLanguageId,
        public readonly int $nameId,
        public readonly string $expectedName,
    ) {
        if ($languageId <= 0) {
            throw new InvalidFormatException('Invalid languageId: ' . $languageId);
        }
        if ($inLanguageId <= 0) {
            throw new InvalidFormatException('Invalid inLanguageId: ' . $inLanguageId);
        }
        if ($nameId <= 0) {
            throw new InvalidFormatException('Invalid nameId: ' . $nameId);
        }
        if ($languageId === $inLanguageId) {
            throw new InvalidFormatException('The writing language must be another language.');
        }
        if (mb_strlen($expectedName) > EntityLanguageName::NAME_MAX_CHARS) {
            throw new InvalidFormatException('The expected name exceeds its column width.');
        }
    }

    /** @return string Tracked removal action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_DELETE;
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed confirmation
     * @throws InvalidFormatException When a required field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = array_key_exists(SignalPayloadConstants::FIELD_DATA, $data)
            ? self::requireArray($data, SignalPayloadConstants::FIELD_DATA)
            : $data;

        return new static(
            self::requireInt($inner, self::languageId),
            self::requireInt($inner, self::inLanguageId),
            self::requireInt($inner, self::nameId),
            self::requireString($inner, self::expectedName),
        );
    }

    /** @return array<string, int|string> Coordinates and the exact expected text */
    public function toArray(): array
    {
        return [
            self::languageId => $this->languageId,
            self::inLanguageId => $this->inLanguageId,
            self::nameId => $this->nameId,
            self::expectedName => $this->expectedName,
        ];
    }
}
