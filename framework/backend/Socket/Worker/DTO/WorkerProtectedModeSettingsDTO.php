<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * Tells the master the protected-mode setting this worker reads.
 *
 * A separate message from registration because the setting can change throughout the node's
 * life. Every worker reports; their values agree because they read the same settings source.
 */
final class WorkerProtectedModeSettingsDTO extends WorkerDTO
{
    public const string TYPE = 'type';
    public const string MANUAL_RESTART_IS_NORMAL = 'manualRestartIsNormal';
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_SETTINGS;

    /**
     * @param bool $manualRestartIsNormal Whether a manual-window restart is routine
     */
    public function __construct(public readonly bool $manualRestartIsNormal)
    {
    }

    /**
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * @return array<string, mixed> DTO data for the worker link
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::MANUAL_RESTART_IS_NORMAL => $this->manualRestartIsNormal,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the setting is absent or not a boolean
     */
    public static function fromArray(array $data): static
    {
        return new static(
            manualRestartIsNormal: self::requireBool($data, self::MANUAL_RESTART_IS_NORMAL),
        );
    }
}
