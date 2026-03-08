<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Capabilities;

use Hilos\Guardian\Capabilities\AbstractGuardianCapability;
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
