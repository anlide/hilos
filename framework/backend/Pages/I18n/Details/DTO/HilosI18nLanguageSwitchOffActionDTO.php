<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Request to switch off one language by its persisted ID. */
final class HilosI18nLanguageSwitchOffActionDTO extends ActionPayloadDTO
{
    /** Payload key: language ID. */
    public const string languageId = 'languageId';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $languageId Persisted language ID
     * @throws InvalidFormatException When the language ID has an invalid format
     */
    public function __construct(public readonly int $languageId)
    {
        if ($languageId <= 0) {
            throw new InvalidFormatException('Invalid language ID: ' . $languageId);
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
     * @throws InvalidFormatException When languageId is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(self::requireInt($inner, self::languageId));
    }

    /** @return array<string, int> Language address */
    public function toArray(): array
    {
        return [self::languageId => $this->languageId];
    }
}
