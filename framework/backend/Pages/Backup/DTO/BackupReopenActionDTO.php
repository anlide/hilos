<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Pages\Backup\AbstractHilosBackupPage;

/**
 * BackupReopenActionDTO - payload for the reopen-the-system action (HIL-676).
 *
 * Carries nothing, and that is the gate of it: the verification window being ended is the
 * one this node stands in, named by the protected-mode runtime row, and the one browser
 * allowed to end it is the session that row recorded as the initiator of the restore. Both
 * are read on the server ({@see AbstractHilosBackupPage}), so there is no field a client
 * could name a different freeze - or a different browser - with.
 */
final class BackupReopenActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::BACKUP_REOPEN;
    }

    /**
     * Create from array (no payload fields).
     *
     * @param array<string, mixed> $data Payload data (ignored)
     * @return static Reopen DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }
}
