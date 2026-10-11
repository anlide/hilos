<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Request to switch off one country by its two-letter code. */
final class HilosI18nCountrySwitchOffActionDTO extends ActionPayloadDTO
{
    /** Payload key: country code. */
    public const string countryCode = 'countryCode';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $countryCode Two lowercase Latin letters
     * @throws InvalidFormatException When the country code has an invalid format
     */
    public function __construct(public readonly string $countryCode)
    {
        if (preg_match('/^[a-z]{2}$/D', $countryCode) !== 1) {
            throw new InvalidFormatException('Invalid country code: ' . $countryCode);
        }
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF;
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed switch-off request
     * @throws InvalidFormatException When countryCode is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(self::requireString($inner, self::countryCode));
    }

    /** @return array<string, string> Country address */
    public function toArray(): array
    {
        return [self::countryCode => $this->countryCode];
    }
}
