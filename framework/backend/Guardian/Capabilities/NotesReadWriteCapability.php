<?php

declare(strict_types=1);

namespace Hilos\Guardian\Capabilities;

use Hilos\Guardian\DTO\CapabilityResult;
use Hilos\Guardian\Storage\InMemoryNotesStorage;

/**
 * Capability for reading and writing notes during guardian investigations.
 */
final class NotesReadWriteCapability extends AbstractGuardianCapability
{
    /**
     * Returns capability identifier.
     *
     * @return string Capability name
     */
    public function getName(): string
    {
        return 'notes.read_write';
    }

    /**
     * Execute capability with payload and context.
     *
     * @param array<string, mixed> $payload Capability payload (scope, mode, note for write)
     * @param array<string, mixed> $context Execution context
     * @return CapabilityResult Result of execution
     */
    public function execute(array $payload = [], array $context = []): CapabilityResult
    {
        $scope = (string) ($payload['scope'] ?? 'default');
        $mode = (string) ($payload['mode'] ?? 'read');

        if ($mode === 'write') {
            $note = (string) ($payload['note'] ?? '');
            if ($note === '') {
                return new CapabilityResult(false, error: 'Empty note');
            }
            InMemoryNotesStorage::write($scope, $note);
        }

        return new CapabilityResult(
            ok: true,
            data: ['scope' => $scope, 'notes' => InMemoryNotesStorage::read($scope)],
        );
    }
}
