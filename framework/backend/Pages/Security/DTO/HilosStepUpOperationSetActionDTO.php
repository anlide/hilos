<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Payload switching confirmation for one declared operation (HIL-495).
 */
final class HilosStepUpOperationSetActionDTO extends ActionPayloadDTO
{
    public const string operationKey = 'operationKey';
    public const string enabled = 'enabled';

    /**
     * @param string $operationKey Operation to switch
     * @param bool $enabled Whether confirmation should be required
     */
    public function __construct(
        public readonly string $operationKey,
        public readonly bool $enabled,
    ) {
    }

    /**
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SECURITY_STEP_UP_OPERATION_SET;
    }

    /**
     * @param array<string, mixed> $data Raw action payload
     * @return static Parsed payload
     * @throws InvalidFormatException When a required field is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            trim(self::requireString($inner, self::operationKey)),
            self::requireBool($inner, self::enabled),
        );
    }

    /**
     * @return array{operationKey: string, enabled: bool} Action payload
     */
    public function toArray(): array
    {
        return [self::operationKey => $this->operationKey, self::enabled => $this->enabled];
    }
}
