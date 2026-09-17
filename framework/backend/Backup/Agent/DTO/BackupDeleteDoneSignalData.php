<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BackupAgent → backup page: how one delete asked for by a bulk run ended.
 *
 * Sent only for a delete that carried a run's key: a single-row delete is answered by the index row
 * leaving the table, as it always was. The reason is the agent's own sentence for a copy it left
 * alone, written for the person who started the run, and null means the copy was deleted.
 */
final class BackupDeleteDoneSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the backup the delete was about. */
    public const string backupId = 'backupId';

    /** Payload key: the bulk run the verdict belongs to. */
    public const string progressKey = 'progressKey';

    /** Payload key: why the copy was left alone, or null when it was deleted. */
    public const string reason = 'reason';

    /** Reason: the copy is the one the running backup is writing. */
    public const string REASON_BEING_TAKEN = 'The copy is being taken right now';

    /** Reason: nothing of the copy was left to delete. */
    public const string REASON_GONE = 'The copy was already gone';

    /** Reason: the copy lies on a node this one cannot reach. */
    public const string REASON_OUT_OF_REACH = 'The copy is held by a node that is out of reach';

    /** Reason: the delete itself failed, the details staying in the agent's log. */
    public const string REASON_FAILED = 'The copy could not be deleted';

    /**
     * @param string $backupId Backup the delete was about
     * @param string $progressKey Bulk run the verdict belongs to
     * @param ?string $reason Why the copy was left alone, or null when it was deleted
     */
    public function __construct(
        public readonly string $backupId,
        public readonly string $progressKey,
        public readonly ?string $reason,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::backupId => $this->backupId,
            self::progressKey => $this->progressKey,
            self::reason => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no backup or no run
     */
    public static function fromArray(array $data): static
    {
        return new static(
            backupId: self::requireString($data, self::backupId),
            progressKey: self::requireString($data, self::progressKey),
            reason: self::optionalString($data, self::reason),
        );
    }
}
