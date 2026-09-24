<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the security_2fa_setting_set action payload (HIL-494).
 *
 * Names one of the six second-factor settings and the value typed for it, as text - the
 * dialog edits text, and the setting's own rule judges it on the way in.
 */
final class HilosSecondFactorSettingSetActionDTO extends ActionPayloadDTO
{
    /** Payload key: the setting key. */
    public const string key = 'key';

    /** Payload key: the value typed. */
    public const string value = 'value';

    /**
     * @param string $key Setting key
     * @param string $value Value typed, as text
     */
    public function __construct(
        public readonly string $key,
        public readonly string $value,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SECURITY_2FA_SETTING_SET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the key or the value is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            key: self::requireString($inner, self::key),
            value: trim(self::requireString($inner, self::value)),
        );
    }

    /**
     * @return array<string, mixed> Data with the key and the value
     */
    public function toArray(): array
    {
        return [
            self::key => $this->key,
            self::value => $this->value,
        ];
    }
}
