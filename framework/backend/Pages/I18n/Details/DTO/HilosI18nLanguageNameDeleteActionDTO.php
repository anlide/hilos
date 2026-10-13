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

    public const string languageCode = 'languageCode';
    public const string inLanguageCode = 'inLanguageCode';
    public const string expectedName = 'expectedName';

    /**
     * @param string $languageCode Named language code
     * @param string $inLanguageCode Writing language code
     * @param string $expectedName Literal text seen when confirmation opened, including empty text
     * @throws InvalidFormatException When coordinates or the expected name violate the wire contract
     */
    public function __construct(
        public readonly string $languageCode,
        public readonly string $inLanguageCode,
        public readonly string $expectedName,
    ) {
        if (preg_match('/^[a-z]{2}$/D', $languageCode) !== 1) {
            throw new InvalidFormatException('Invalid language code: ' . $languageCode);
        }
        if (preg_match('/^[a-z]{2}$/D', $inLanguageCode) !== 1 || $languageCode === $inLanguageCode) {
            throw new InvalidFormatException('The writing language must be another two-letter language code.');
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
            self::requireString($inner, self::languageCode),
            self::requireString($inner, self::inLanguageCode),
            self::requireString($inner, self::expectedName),
        );
    }

    /** @return array<string, string> Coordinates and the exact expected text */
    public function toArray(): array
    {
        return [
            self::languageCode => $this->languageCode,
            self::inLanguageCode => $this->inLanguageCode,
            self::expectedName => $this->expectedName,
        ];
    }
}
