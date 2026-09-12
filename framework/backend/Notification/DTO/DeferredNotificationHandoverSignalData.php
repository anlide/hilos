<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationDraft;

/**
 * DeferredNotificationHandoverSignalData - BackupAgent -> notifications library payload for
 * HILOS_NOTIFICATION_HANDOVER (HIL-846).
 *
 * One batch of the notices a restore left while nobody could be told, offered by the agent that
 * holds the queue file ({@see BackupAgent}) to the library that sends them
 * ({@see AbstractNotificationsLibraryAgent}). Each notice travels as the line
 * {@see DeferredNotificationQueue} writes, which is already {@see NotificationEmitSignalData}'s
 * payload: one vocabulary for the file, for this batch and for a single emit.
 *
 * The batch id rides along for the reason it does on the session handover: the receipt names it,
 * and the holder removes only the file of the batch the receipt is for.
 */
final class DeferredNotificationHandoverSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: id of the batch being handed over. */
    public const string batch = 'batch';

    /** Payload key: notices of the batch, each as the emit signal carries it. */
    public const string notifications = 'notifications';

    /**
     * @param string $batch Id of the batch being handed over
     * @param list<NotificationDraft> $notifications Notices of the batch, in the order they were queued
     */
    public function __construct(
        public readonly string $batch,
        public readonly array $notifications,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::batch => $this->batch,
            self::notifications => array_map(
                static fn(NotificationDraft $draft): array => NotificationEmitSignalData::fromDraft($draft)->toArray(),
                $this->notifications,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no batch, or a notice in it is not one
     */
    public static function fromArray(array $data): static
    {
        $notifications = [];
        foreach (self::requireArray($data, self::notifications) as $notification) {
            if (!is_array($notification)) {
                throw new InvalidFormatException('handed-over notification is not an object');
            }

            $notifications[] = NotificationEmitSignalData::fromArray($notification)->toDraft();
        }

        return new static(
            batch: self::requireString($data, self::batch),
            notifications: $notifications,
        );
    }
}
