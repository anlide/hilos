<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

/**
 * UnknownActionPayloadDTO - DTO for unrecognized actions.
 *
 * Used as fallback when action is not recognized by factory.
 * Preserves raw data for debugging/logging.
 */
class UnknownActionPayloadDTO extends ActionPayloadDTO
{
    /**
     * Creates unknown action payload DTO with raw data preserved.
     *
     * @param string $action Action name
     * @param array<string, mixed> $data Raw payload data
     */
    public function __construct(
        private string $action,
        private array $data,
    ) {
    }

    /**
     * Gets the action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Returns the raw payload data.
     *
     * @return array<string, mixed> Raw payload data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Data array (action key required, data optional)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            $data['action'] ?? '',
            $data['data'] ?? $data,
        );
    }

    /**
     * Converts the DTO to array.
     *
     * @return array<string, mixed> Action and data keys
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'data' => $this->data,
        ];
    }
}
