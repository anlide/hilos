<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Request to switch off one language by its two-letter code. */
final class HilosI18nLanguageSwitchOffActionDTO extends ActionPayloadDTO
{
    /** Payload key: language code. */
    public const string languageCode = 'languageCode';

    public const array SECRET_FIELDS = [];

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

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF;
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed switch-off request
     * @throws InvalidFormatException When languageCode is missing or invalid
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
