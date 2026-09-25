<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * One protected operation on the security administration page (HIL-495).
 */
final class HilosSecurityStepUpTableRow extends AbstractTableRow
{
    public const string operationKey = 'operationKey';
    public const string label = 'label';
    public const string owner = 'owner';
    public const string enabled = 'enabled';

    /**
     * @param string $operationKey Declared operation key and row key
     * @param string $label Administration label
     * @param string $owner Operation owner: framework or project
     * @param bool $enabled Whether confirmation is required
     */
    public function __construct(
        public string $operationKey,
        public string $label,
        public string $owner,
        public bool $enabled,
    ) {
    }

    /** @return string Operation key */
    public function getRowKey(): string
    {
        return $this->operationKey;
    }

    /** @return string Payload key carrying the row key */
    public static function keyField(): string
    {
        return self::operationKey;
    }

    /** @return array<string, mixed> Row payload */
    public function toArray(): array
    {
        return [
            self::operationKey => $this->operationKey,
            self::label => $this->label,
            self::owner => $this->owner,
            self::enabled => $this->enabled,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed row
     */
    public static function fromArray(array $data): static
    {
        return new static(
            (string)$data[self::operationKey],
            (string)$data[self::label],
            (string)$data[self::owner],
            (bool)$data[self::enabled],
        );
    }
}
