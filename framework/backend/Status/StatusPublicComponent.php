<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Public identity and verdict only; no observation or agent metadata. */
final readonly class StatusPublicComponent
{
    /**
     * @param string $componentKey Catalog key
     * @param string $name Public name
     * @param ?string $description Public description, or null
     * @param StatusComponentState $state Current permitted verdict
     */
    public function __construct(
        public string $componentKey,
        public string $name,
        public ?string $description,
        public StatusComponentState $state,
    ) {
    }

    /** @return array<string, mixed> Literal public v1 component */
    public function toArray(): array
    {
        return [
            'componentKey' => $this->componentKey,
            'name' => $this->name,
            'description' => $this->description,
            'state' => $this->state->value,
        ];
    }
}
