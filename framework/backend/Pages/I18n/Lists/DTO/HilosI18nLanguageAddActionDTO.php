<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Request to add one switched-off language by its two-letter code. */
final class HilosI18nLanguageAddActionDTO extends ActionPayloadDTO
{
    public const string languageCode = 'languageCode';
    public const string nativeName = 'nativeName';
    public const string rtl = 'rtl';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $languageCode Two lowercase Latin letters
     * @param string $nativeName Name in the language itself, validated by the write door
     * @param bool $rtl Whether writing runs right to left
     * @throws InvalidFormatException When the language code has an invalid format
     */
    public function __construct(
        public readonly string $languageCode,
        public readonly string $nativeName,
        public readonly bool $rtl,
    ) {
        if (preg_match('/^[a-z]{2}$/D', $languageCode) !== 1) {
            throw new InvalidFormatException('Invalid language code: ' . $languageCode);
        }
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD;
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed language add request
     * @throws InvalidFormatException When a required field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            self::requireString($inner, self::languageCode),
            self::requireString($inner, self::nativeName),
            self::requireBool($inner, self::rtl),
        );
    }

    /** @return array{languageCode: string, nativeName: string, rtl: bool} Language creation fields */
    public function toArray(): array
    {
        return [
            self::languageCode => $this->languageCode,
            self::nativeName => $this->nativeName,
            self::rtl => $this->rtl,
        ];
    }
}
