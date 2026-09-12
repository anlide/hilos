<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DeferredSessionCarryoverQueue;
use Hilos\Auth\Session\SessionCarryover;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * DeferredSessionCarryoverHandoverSignalData - BackupAgent -> sessions library payload for
 * HILOS_SESSION_CARRYOVER_HANDOVER (HIL-846).
 *
 * One batch of the logins a restore photographed, offered by the agent that holds the queue file
 * ({@see BackupAgent}) to the library that owns the sessions table
 * ({@see AbstractSessionsLibraryAgent}). Each session travels as exactly the line the queue file
 * holds ({@see DeferredSessionCarryoverQueue::carryoverToArray()}), so the file and the wire are
 * one vocabulary and cannot drift apart.
 *
 * The batch id rides along because the receipt names it: the holder removes only the file of the
 * batch a receipt is for, and a receipt for an earlier batch that arrived late must not delete a
 * later one unread.
 */
final class DeferredSessionCarryoverHandoverSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: id of the batch being handed over. */
    public const string batch = 'batch';

    /** Payload key: sessions of the batch, each as the queue file writes it. */
    public const string sessions = 'sessions';

    /**
     * @param string $batch Id of the batch being handed over
     * @param list<SessionCarryover> $sessions Sessions of the batch, in the order they were queued
     */
    public function __construct(
        public readonly string $batch,
        public readonly array $sessions,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::batch => $this->batch,
            self::sessions => array_map(DeferredSessionCarryoverQueue::carryoverToArray(...), $this->sessions),
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no batch, or a session in it is not one
     */
    public static function fromArray(array $data): static
    {
        $sessions = [];
        foreach (self::requireArray($data, self::sessions) as $session) {
            if (!is_array($session)) {
                throw new InvalidFormatException('handed-over session is not an object');
            }

            $sessions[] = DeferredSessionCarryoverQueue::carryoverFromArray($session);
        }

        return new static(
            batch: self::requireString($data, self::batch),
            sessions: $sessions,
        );
    }
}
