<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Hilos;

/**
 * The words a maintenance surface shows for one operation, resolved from the stub registry.
 *
 * Both protected-mode frames carry copy — the welcome frame a fresh connection gets and the
 * frame pushed to connections that were already open — and both compose it here, on the daemon
 * side, so the sentence travels the wire and the frontend never authors it (the Hilos i18n model:
 * user-facing text is a backend stage). Resolution is exact operation first, then
 * {@see ProtectedModeStubConstants::DEFAULT_OPERATION}; a registry that carries neither yields
 * nulls and the frontend falls back to its last-resort copy, which is the only case where the
 * screen is worded by the client.
 */
final class ProtectedModeStubCopy
{
    /**
     * @param ?string $title Heading of the surface, or null when the registry names none
     * @param ?string $message Sentence under the heading, or null when the registry names none
     */
    private function __construct(
        public readonly ?string $title,
        public readonly ?string $message,
    ) {
    }

    /**
     * Resolves the copy registered for an operation, falling back to the default entry.
     *
     * @param ?string $operation Operation name recorded on the freeze row, or null when none is
     *                           recorded — which resolves to the default entry, same as an
     *                           operation nobody registered
     * @return self Copy for that operation; both fields null when the registry answers nothing
     */
    public static function forOperation(?string $operation): self
    {
        return self::fromRegistry(Hilos::protectedModeStubRegistry(), $operation);
    }

    /**
     * Resolves the copy an explicit registry holds for an operation.
     *
     * The rule itself, separated from where the registry comes from: {@see forOperation()} reads
     * the project's, while a caller holding one of its own - a test pinning the fallbacks, a
     * future operation carrying its own catalog - resolves against that instead.
     *
     * @param array<string, mixed> $registry Stub entries keyed by operation name
     * @param ?string $operation Operation to resolve, or null for the default entry
     * @return self Copy for that operation; both fields null when the registry answers nothing
     */
    public static function fromRegistry(array $registry, ?string $operation): self
    {
        $entry = $operation === null ? null : ($registry[$operation] ?? null);
        $entry ??= $registry[ProtectedModeStubConstants::DEFAULT_OPERATION] ?? null;
        if (!is_array($entry)) {
            return new self(null, null);
        }

        return new self(
            self::text($entry, ProtectedModeStubConstants::TITLE),
            self::text($entry, ProtectedModeStubConstants::MESSAGE),
        );
    }

    /**
     * Reads one copy field off a registry entry.
     *
     * @param array<string, mixed> $entry Registry entry to read
     * @param string $field Entry field to read
     * @return ?string Field value, or null when the entry does not carry it as text
     */
    private static function text(array $entry, string $field): ?string
    {
        $value = $entry[$field] ?? null;

        return is_string($value) ? $value : null;
    }
}
