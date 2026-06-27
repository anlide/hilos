<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tables\HilosUser\DTO;

use Demo\SimplePoll\Database\Object\Item\User as ObjectUser;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the Hilos users rename action payload.
 */
final class HilosUserUpdateActionDTO extends ActionPayloadDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }

    /**
     * @return string The framework Hilos user update action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_USER_UPDATE;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($inner) && isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        $name = isset($inner[ObjectUser::name]) && is_string($inner[ObjectUser::name]) ? trim($inner[ObjectUser::name]) : '';

        return new static(
            id: (int) ($inner[ObjectUser::id] ?? 0),
            name: $name,
        );
    }

    /**
     * @return array<string, mixed> Wire payload (id + name)
     */
    public function toArray(): array
    {
        return [
            ObjectUser::id => $this->id,
            ObjectUser::name => $this->name,
        ];
    }
}
