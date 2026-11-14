<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Status\Exception\StatusException;

/** Public identity, verdict and start of observation only; no agent metadata. */
final readonly class StatusPublicComponent
{
    /**
     * @param string $componentKey Catalog key
     * @param string $name Public name
     * @param ?string $description Public description, or null
     * @param StatusComponentState $state Current permitted verdict
     * @param ?int $observedSince Start of observation in signed epoch milliseconds UTC; null only when read from a v1-v3 file
     */
    public function __construct(
        public string $componentKey,
        public string $name,
        public ?string $description,
        public StatusComponentState $state,
        public ?int $observedSince,
    ) {
    }

    /**
     * @return array<string, mixed> Literal public v4 component
     * @throws StatusException When the component carries no start of observation, as one read from a v1-v3 file
     */
    public function toArray(): array
    {
        return [
            'componentKey' => $this->componentKey,
            'name' => $this->name,
            'description' => $this->description,
            'state' => $this->state->value,
            'observedSince' => $this->observedSince
                ?? throw new StatusException('A public Status component cannot be written without its start of observation.'),
        ];
    }
}
