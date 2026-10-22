<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the framework OAuth return-address table (HIL-286).
 *
 * The table has one row: the shared address every provider redirects back to, its
 * effective value and the layer it comes from. The identity rides {@see rowKey}, the
 * setting key of the address.
 */
final class HilosSecurityOAuthRedirectTableRow extends AbstractTableRow
{
    /** Payload key of the row identity: the setting key of the address. */
    public const string rowKey = 'rowKey';

    public const string value = 'value';
    public const string source = 'source';
    public const string setState = 'setState';

    public const string defaultValue = 'defaultValue';
    public const string defaultSource = 'defaultSource';
    public const string defaultSetState = 'defaultSetState';

    /**
     * @param string $rowKey Row key: the setting key of the address
     * @param string $value Effective return address, empty when none is set
     * @param string $source Layer the address comes from (db|env|default)
     * @param bool $setState Whether the address is non-empty
     * @param string $defaultValue Return address without the settings override
     * @param string $defaultSource Fallback layer (env|default)
     * @param bool $defaultSetState Whether the fallback address is non-empty
     */
    public function __construct(
        public string $rowKey,
        public string $value,
        public string $source,
        public bool $setState,
        public string $defaultValue,
        public string $defaultSource,
        public bool $defaultSetState,
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
     * Serializes the row to the return-address table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::value => $this->value,
            self::source => $this->source,
            self::setState => $this->setState,
            self::defaultValue => $this->defaultValue,
            self::defaultSource => $this->defaultSource,
            self::defaultSetState => $this->defaultSetState,
        ];
    }

    /**
     * Builds the return-address row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed row
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: (string) $data[self::rowKey],
            value: (string) $data[self::value],
            source: (string) $data[self::source],
            setState: (bool) $data[self::setState],
            defaultValue: (string) $data[self::defaultValue],
            defaultSource: (string) $data[self::defaultSource],
            defaultSetState: (bool) $data[self::defaultSetState],
        );
    }
}
