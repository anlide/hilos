<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Shared language address for the card and list removal actions. */
abstract class AbstractHilosI18nLanguageDeleteActionDTO extends ActionPayloadDTO
{
    public const string languageId = 'languageId';

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

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed language removal request
     * @throws InvalidFormatException When the language ID is absent or malformed
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
