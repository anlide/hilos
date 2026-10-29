<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the framework two-factor settings table (HIL-494).
 *
 * One row per second-factor setting: its key, the value in force and the catalog default,
 * both as the text the administration screen shows and edits.
 *
 * {@see editDisabledReason} is the full sentence the screen shows when it explains why a row
 * cannot be changed: every setting below the general switch is locked while the second factor
 * is off. It is null for the general switch itself and for every row while the factor is on.
 */
final class HilosSecurityTwoFactorTableRow extends AbstractTableRow
{
    /** Payload key of the row identity: the setting key. */
    public const string rowKey = 'rowKey';

    public const string value = 'value';
    public const string defaultValue = 'defaultValue';
    public const string editDisabledReason = 'editDisabledReason';

    /**
     * @param string $rowKey Row key: the setting key
     * @param string $value Value in force, as text
     * @param string $defaultValue Catalog default, as text
     * @param ?string $editDisabledReason Why the setting is locked, or null when that refusal does not apply
     */
    public function __construct(
        public string $rowKey,
        public string $value,
        public string $defaultValue,
        public ?string $editDisabledReason,
    ) {
    }

    /**
     * Returns the stable table row key.
     *
     * @return string Row key
     */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /**
     * Serializes the row to the table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::value => $this->value,
            self::defaultValue => $this->defaultValue,
            self::editDisabledReason => $this->editDisabledReason,
        ];
    }

    /**
     * Builds the row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed row
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: (string) $data[self::rowKey],
            value: (string) $data[self::value],
            defaultValue: (string) $data[self::defaultValue],
            editDisabledReason: isset($data[self::editDisabledReason]) ? (string) $data[self::editDisabledReason] : null,
        );
    }
}
