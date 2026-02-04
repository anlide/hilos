<?php

declare(strict_types=1);

namespace Hilos\DTO\Action;

/**
 * UnknownActionPayloadDTO - DTO for unrecognized actions
 *
 * Used as fallback when action is not recognized by factory.
 * Preserves raw data for debugging/logging.
 */
class UnknownActionPayloadDTO extends ActionPayloadDTO
{
    /**
     * Constructor
     *
     * @param string $action Action name
     * @param array $data Raw payload data
     */
    public function __construct(
        private string $action,
        private array $data,
    ) {
    }

    /**
     * Get action name
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get raw payload data
     *
     * @return array Raw data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Create from array
     *
     * @param array $data Data array (must contain 'action' and optionally 'data')
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static(
            $data['action'] ?? '',
            $data['data'] ?? $data,
        );
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'data' => $this->data,
        ];
    }
}
