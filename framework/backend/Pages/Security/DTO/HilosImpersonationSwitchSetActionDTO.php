<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the security_impersonation_switch_set action payload (HIL-1170).
 *
 * Names one of the six yes-or-no impersonation settings and the position it is switched to.
 */
final class HilosImpersonationSwitchSetActionDTO extends ActionPayloadDTO
{
    /** Payload key: the setting key. */
    public const string key = 'key';

    /** Payload key: the position the switch goes to. */
    public const string enabled = 'enabled';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $key Setting key
     * @param bool $enabled Position the switch goes to
     */
    public function __construct(
        public readonly string $key,
        public readonly bool $enabled,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SECURITY_IMPERSONATION_SWITCH_SET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the key is absent or not a string, or the position is absent or not a boolean
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            key: trim(self::requireString($inner, self::key)),
            enabled: self::requireBool($inner, self::enabled),
        );
    }

    /**
     * @return array{key: string, enabled: bool} Data with the key and the position
     */
    public function toArray(): array
    {
        return [
            self::key => $this->key,
            self::enabled => $this->enabled,
        ];
    }
}
