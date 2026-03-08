<?php

declare(strict_types=1);

namespace Hilos\Guardian\Capabilities;

use Hilos\Guardian\DTO\CapabilityResult;
use Hilos\Guardian\Storage\InMemoryNotesStorage;

final class NotesReadWriteCapability extends AbstractGuardianCapability
{
    public function getName(): string
    {
        return 'notes.read_write';
    }

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
