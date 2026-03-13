<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Capabilities;

use Hilos\Guardian\Capabilities\AbstractGuardianCapability;
use Hilos\Guardian\DTO\CapabilityResult;
use Hilos\Guardian\Storage\InMemoryNotesStorage;

/**
 * NotesReadWriteCapability - Read/write notes for guardian scope.
 *
 * Supports scope and mode via payload. Mode 'write' stores note, 'read' returns notes.
 */
final class NotesReadWriteCapability extends AbstractGuardianCapability
{
    /**
     * Get capability name.
     *
     * @return string Capability identifier
     */
    public function getName(): string
    {
        return 'notes.read_write';
    }

    /**
     * Execute read or write note operation.
     *
     * @param array<string, mixed> $payload Scope (default: guardian), mode (read/write), note (for write)
     * @param array<string, mixed> $context Execution context (unused)
     * @return CapabilityResult Success with notes data or error
     */
    public function execute(array $payload = [], array $context = []): CapabilityResult
    {
        $scope = (string) ($payload['scope'] ?? 'guardian');
        $mode = (string) ($payload['mode'] ?? 'read');

        if ($mode === 'write') {
            $note = trim((string) ($payload['note'] ?? ''));
            if ($note === '') {
                return new CapabilityResult(false, error: 'Note is empty');
            }
            InMemoryNotesStorage::write($scope, $note);
        }

        return new CapabilityResult(true, ['scope' => $scope, 'notes' => InMemoryNotesStorage::read($scope)]);
    }
}
