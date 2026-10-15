<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Shared payload for saving a switched-off language through either page. */
abstract class AbstractHilosI18nLanguageUpdateActionDTO extends ActionPayloadDTO
{
    public const string languageId = 'languageId';
    public const string nativeName = 'nativeName';
    public const string rtl = 'rtl';

    /**
     * @param int $languageId Persisted language ID
     * @param string $nativeName Name in the language itself
     * @param bool $rtl Whether writing runs right to left
     * @throws InvalidFormatException When the language ID has an invalid format
     */
    public function __construct(
        public readonly int $languageId,
        public readonly string $nativeName,
        public readonly bool $rtl,
    ) {
        if ($languageId <= 0) {
            throw new InvalidFormatException('Invalid language ID: ' . $languageId);
        }
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed language update request
     * @throws InvalidFormatException When a required field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            self::requireInt($inner, self::languageId),
            self::requireString($inner, self::nativeName),
            self::requireBool($inner, self::rtl),
        );
    }

    /** @return array<string, mixed> Language address, native name and writing direction */
    public function toArray(): array
    {
        return [
            self::languageId => $this->languageId,
            self::nativeName => $this->nativeName,
            self::rtl => $this->rtl,
        ];
    }
}
