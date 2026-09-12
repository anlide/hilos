<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;

/**
 * DeferredNoticesSentSignalData - notifications library -> BackupAgent payload for
 * BACKUP_AGENT_NOTICES_SENT (HIL-846).
 *
 * The receipt for one handed-over batch of deferred notices, with the meaning the session receipt
 * has ({@see DeferredSessionsCarriedSignalData}): the batch is the library's now, whatever became
 * of each notice in it. {@see AbstractNotificationsLibraryAgent} answers after every pass, so a
 * notice that could not be emitted is counted and logged there instead of being offered forever.
 *
 * Unlike the session receipt it feeds no master: nothing waits for a letter before the browsers
 * reload, so the two numbers are only what the holder writes in its log.
 */
final class DeferredNoticesSentSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: id of the batch this receipt is for. */
    public const string batch = 'batch';

    /** Payload key: notices the library emitted. */
    public const string sent = 'sent';

    /** Payload key: notices the library could not emit. */
    public const string dropped = 'dropped';

    /**
     * @param string $batch Id of the batch this receipt is for
     * @param int $sent Notices the library emitted
     * @param int $dropped Notices the library could not emit
     */
    public function __construct(
        public readonly string $batch,
        public readonly int $sent,
        public readonly int $dropped,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::batch => $this->batch,
            self::sent => $this->sent,
            self::dropped => $this->dropped,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no batch or misses one of the counts
     */
    public static function fromArray(array $data): static
    {
        return new static(
            batch: self::requireString($data, self::batch),
            sent: self::requireInt($data, self::sent),
            dropped: self::requireInt($data, self::dropped),
        );
    }
}
