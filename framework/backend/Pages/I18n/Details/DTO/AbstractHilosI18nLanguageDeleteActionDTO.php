<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Shared language address for the card and list removal actions. */
abstract class AbstractHilosI18nLanguageDeleteActionDTO extends ActionPayloadDTO
{
    public const string languageCode = 'languageCode';

    /**
     * @param string $languageCode Two lowercase Latin letters
     * @throws InvalidFormatException When the language code has an invalid format
     */
    public function __construct(public readonly string $languageCode)
    {
        if (preg_match('/^[a-z]{2}$/D', $languageCode) !== 1) {
            throw new InvalidFormatException('Invalid language code: ' . $languageCode);
        }
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed language removal request
     * @throws InvalidFormatException When the language code is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(self::requireString($inner, self::languageCode));
    }

    /** @return array<string, string> Language address */
    public function toArray(): array
    {
        return [self::languageCode => $this->languageCode];
    }
}
