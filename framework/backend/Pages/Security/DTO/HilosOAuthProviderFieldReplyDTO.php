<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * Reply to a write of one OAuth provider field: where the field stands now (HIL-286).
 *
 * The effective value and its layer after the write, so the modal that sent it can close on
 * a settled answer. For the client secret the value is always null and only {@see setState}
 * says anything - the reply to a secret write carries no value, exactly as its table row
 * carries none. The row on screen is repainted by the table, not by this ack.
 */
final class HilosOAuthProviderFieldReplyDTO extends ActionReplyDTO
{
    /** Reply key: the provider key. */
    public const string providerKey = 'providerKey';

    /** Reply key: the field name. */
    public const string field = 'field';

    /** Reply key: the layer the effective value comes from. */
    public const string source = 'source';

    /** Reply key: the effective value, null for the secret. */
    public const string value = 'value';

    /** Reply key: whether the effective value is non-empty. */
    public const string setState = 'setState';

    /**
     * @param string $providerKey Provider key
     * @param string $field Field name
     * @param string $source Layer the effective value comes from (db|env|default)
     * @param ?string $value Effective value, or null for the secret
     * @param bool $setState Whether the effective value is non-empty
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $field,
        public readonly string $source,
        public readonly ?string $value,
        public readonly bool $setState,
    ) {
    }

    /**
     * Reads a reply back from its wire form.
     *
     * Present for the base contract, not for a caller: the ack travels flat and the browser
     * reads it as data.
     *
     * @param array<string, mixed> $data Wire form of a reply
     * @return static Restored reply
     * @throws InvalidFormatException When a field is absent or holds a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            providerKey: self::requireString($data, self::providerKey),
            field: self::requireString($data, self::field),
            source: self::requireString($data, self::source),
            value: self::optionalString($data, self::value),
            setState: self::requireBool($data, self::setState),
        );
    }

    /**
     * @return array<string, mixed> Reply as it goes out on the action ack
     */
    public function toArray(): array
    {
        return [
            self::providerKey => $this->providerKey,
            self::field => $this->field,
            self::source => $this->source,
            self::value => $this->value,
            self::setState => $this->setState,
        ];
    }
}
