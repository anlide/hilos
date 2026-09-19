<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the framework sign-in methods table (HIL-427).
 *
 * One row per method the project wired: its key, the name the screen shows it under,
 * whether it is switched on, whether the installation can actually serve it, and - for a
 * provider - the key its own screen is reached by. The identity rides {@see methodKey},
 * never a field named `id`.
 */
final class HilosSecuritySignInMethodsTableRow extends AbstractTableRow
{
    public const string methodKey = 'methodKey';
    public const string label = 'label';
    public const string enabled = 'enabled';
    public const string ready = 'ready';
    public const string providerKey = 'providerKey';

    /**
     * @param string $methodKey Method key (row key), e.g. 'password' or 'oauth:github'
     * @param string $label Name the screen shows the method under
     * @param bool $enabled Whether the method is switched on
     * @param bool $ready Whether the installation can serve the method: a provider configured, a code deliverable
     * @param ?string $providerKey Provider key for a provider method, whose own screen it links to; null otherwise
     */
    public function __construct(
        public string $methodKey,
        public string $label,
        public bool $enabled,
        public bool $ready,
        public ?string $providerKey,
    ) {
    }

    /**
     * Returns the stable table row key (the method key).
     *
     * @return string Row key
     */
    public function getRowKey(): string
    {
        return $this->methodKey;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::methodKey;
    }

    /**
     * Serializes the row to the sign-in methods table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::methodKey => $this->methodKey,
            self::label => $this->label,
            self::enabled => $this->enabled,
            self::ready => $this->ready,
            self::providerKey => $this->providerKey,
        ];
    }

    /**
     * Builds a sign-in methods row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed sign-in methods table row
     */
    public static function fromArray(array $data): static
    {
        $providerKey = $data[self::providerKey] ?? null;

        return new static(
            methodKey: (string) $data[self::methodKey],
            label: (string) $data[self::label],
            enabled: (bool) $data[self::enabled],
            ready: (bool) $data[self::ready],
            providerKey: is_string($providerKey) ? $providerKey : null,
        );
    }
}
