<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Auth\OAuth\OAuthConfigField;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the framework OAuth provider fields table (HIL-286).
 *
 * One row per {@see OAuthConfigField} of every declared provider; the provider screen
 * shows the rows of its own provider. A row carries the field's effective value and the
 * layer it came from; {@see value} is always null for the {@see secret} field, whose
 * {@see setState} says whether one is in force - the secret is replaced from the admin,
 * never read back. The identity rides {@see rowKey}, never a field named `id`.
 */
final class HilosSecurityOAuthProviderFieldsTableRow extends AbstractTableRow
{
    /** Payload key of the row identity: the provider key and the field name. */
    public const string rowKey = 'rowKey';

    public const string providerKey = 'providerKey';
    public const string field = 'field';
    public const string label = 'label';
    public const string type = 'type';
    public const string secret = 'secret';
    public const string value = 'value';
    public const string source = 'source';
    public const string setState = 'setState';

    /**
     * @param string $rowKey Row key: the provider key and the field name
     * @param string $providerKey Owning provider key
     * @param string $field Field name (an {@see OAuthConfigField} value)
     * @param string $label Human field label
     * @param string $type Value type (see SettingsCatalogConstants::TYPE_*)
     * @param bool $secret Whether the field is write-only
     * @param ?string $value Effective value, or null for the secret
     * @param string $source Layer the effective value comes from (db|env|default)
     * @param bool $setState Whether the effective value is non-empty
     */
    public function __construct(
        public string $rowKey,
        public string $providerKey,
        public string $field,
        public string $label,
        public string $type,
        public bool $secret,
        public ?string $value,
        public string $source,
        public bool $setState,
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
     * Serializes the row to the fields table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::providerKey => $this->providerKey,
            self::field => $this->field,
            self::label => $this->label,
            self::type => $this->type,
            self::secret => $this->secret,
            self::value => $this->value,
            self::source => $this->source,
            self::setState => $this->setState,
        ];
    }

    /**
     * Builds a fields row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed fields table row
     */
    public static function fromArray(array $data): static
    {
        $value = $data[self::value] ?? null;

        return new static(
            rowKey: (string) $data[self::rowKey],
            providerKey: (string) $data[self::providerKey],
            field: (string) $data[self::field],
            label: (string) $data[self::label],
            type: (string) $data[self::type],
            secret: (bool) $data[self::secret],
            value: is_string($value) ? $value : null,
            source: (string) $data[self::source],
            setState: (bool) $data[self::setState],
        );
    }
}
