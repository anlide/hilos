<?php

declare(strict_types=1);

namespace Hilos\Tables\Status;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Status\StatusComponentSnapshot;

/** One declared component, present even before its first observation. */
final class HilosStatusComponentsTableRow extends AbstractTableRow
{
    public const string componentKey = 'componentKey';
    public const string name = 'name';
    public const string description = 'description';
    public const string agentType = 'agentType';
    public const string state = 'state';

    /**
     * @param string $componentKey Stable catalog key
     * @param string $name Current declared name
     * @param ?string $description Optional catalog description
     * @param string $agentType Declared permanent agent type
     * @param string $state Current Status verdict
     */
    public function __construct(
        public readonly string $componentKey,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $agentType,
        public readonly string $state,
    ) {
    }

    /**
     * @param StatusComponentSnapshot $component Current read projection
     * @return self Catalog identity and current state
     */
    public static function fromSnapshot(StatusComponentSnapshot $component): self
    {
        return new self($component->component->componentKey, $component->component->name, $component->component->description,
            $component->component->agentType, $component->state->value);
    }

    /** @return string Stable catalog row key */
    public function getRowKey(): string
    {
        return $this->componentKey;
    }

    /** @return string Field carrying the row key */
    public static function keyField(): string
    {
        return self::componentKey;
    }

    /**
     * @param array<string, mixed> $data Component row payload
     * @return static Hydrated component row
     * @throws InvalidFormatException When a required field has an invalid type
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::componentKey), self::requireString($data, self::name),
            self::optionalString($data, self::description), self::requireString($data, self::agentType),
            self::requireString($data, self::state));
    }

    /** @return array<string, string|null> Component slot without ordering metadata */
    public function toArray(): array
    {
        return [self::componentKey => $this->componentKey, self::name => $this->name, self::description => $this->description,
            self::agentType => $this->agentType, self::state => $this->state];
    }
}
