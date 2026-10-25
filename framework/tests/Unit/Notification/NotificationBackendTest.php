<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Groups\AbstractHilosNotificationsGroup;
use Hilos\Notification\DTO\NotificationCreatedSignalData;
use Hilos\Notification\DTO\NotificationMarkAllReadPayloadDTO;
use Hilos\Notification\DTO\NotificationMarkReadPayloadDTO;
use Hilos\Notification\DTO\NotificationReadSignalData;
use Hilos\Notification\DTO\NotificationsSnapshotSignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationGroup;
use Hilos\Notification\NotificationSeverity;
use Hilos\Notification\NotificationSignalName;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the durable notification backend contract (HIL-102).
 *
 * Locks the pure, DB-free pieces: the severity value set, the per-recipient group
 * name, the signal payload round-trips (with the "all" mark-read sentinel), and
 * the action payload DTO parsing including the FIELD_DATA envelope unwrap.
 */
final class NotificationBackendTest extends TestCase
{
    public function testSeverityConstantsMatchSqlEnumValues(): void
    {
        self::assertSame('info', NotificationSeverity::INFO);
        self::assertSame('success', NotificationSeverity::SUCCESS);
        self::assertSame('warning', NotificationSeverity::WARNING);
        self::assertSame('error', NotificationSeverity::ERROR);
        self::assertSame(
            ['info', 'success', 'warning', 'error'],
            NotificationSeverity::ALL,
        );
    }

    public function testSeverityIsValidAcceptsKnownAndRejectsUnknown(): void
    {
        foreach (NotificationSeverity::ALL as $severity) {
            self::assertTrue(NotificationSeverity::isValid($severity));
        }
        self::assertFalse(NotificationSeverity::isValid(''));
        self::assertFalse(NotificationSeverity::isValid('critical'));
        self::assertFalse(NotificationSeverity::isValid('Info'));
    }

    public function testGroupNameIsPerRecipient(): void
    {
        self::assertSame('hilos_notifications:42', NotificationGroup::forUser(42));
        self::assertNotSame(NotificationGroup::forUser(1), NotificationGroup::forUser(2));
    }

    public function testDraftDefaultsToInfoAndNoBodyOrData(): void
    {
        $draft = new NotificationDraft(userId: 7, type: 'backup.completed', title: 'Backup done');

        self::assertSame(7, $draft->userId);
        self::assertSame('backup.completed', $draft->type);
        self::assertSame('Backup done', $draft->title);
        self::assertSame(NotificationSeverity::INFO, $draft->severity);
        self::assertNull($draft->body);
        self::assertNull($draft->data);
    }

    public function testCreatedSignalDataRoundTrips(): void
    {
        $data = new NotificationCreatedSignalData(
            id: 5,
            userId: 7,
            type: 'backup.completed',
            severity: NotificationSeverity::SUCCESS,
            title: 'Backup done',
            body: 'Full backup finished',
            data: ['scope' => 'full'],
            readAt: null,
            createdAt: 1785060000000,
        );

        $restored = NotificationCreatedSignalData::fromArray($data->toArray());

        self::assertSame(5, $restored->id);
        self::assertSame(7, $restored->userId);
        self::assertSame('backup.completed', $restored->type);
        self::assertSame(NotificationSeverity::SUCCESS, $restored->severity);
        self::assertSame('Backup done', $restored->title);
        self::assertSame('Full backup finished', $restored->body);
        self::assertSame(['scope' => 'full'], $restored->data);
        self::assertNull($restored->readAt);
        self::assertSame(1785060000000, $restored->createdAt);

        // JSON roundtrip (simulating wire serialization)
        $encoded = json_encode($data->toArray(), JSON_THROW_ON_ERROR);
        $fromWire = NotificationCreatedSignalData::fromArray(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(1785060000000, $fromWire->createdAt);
        self::assertNull($fromWire->readAt);
    }

    public function testCreatedSignalDataRoundTripsWithReadAtAndZeroMs(): void
    {
        $read = new NotificationCreatedSignalData(
            id: 1,
            userId: 2,
            type: 'test',
            severity: NotificationSeverity::INFO,
            title: 'Read title',
            body: null,
            data: null,
            readAt: 1785060005000,
            createdAt: 1785060000000,
        );
        $restoredRead = NotificationCreatedSignalData::fromArray($read->toArray());
        self::assertSame(1785060005000, $restoredRead->readAt);
        self::assertSame(1785060000000, $restoredRead->createdAt);

        $zero = new NotificationCreatedSignalData(
            id: 2,
            userId: 3,
            type: 'test',
            severity: NotificationSeverity::INFO,
            title: 'Zero moment',
            body: null,
            data: null,
            readAt: 0,
            createdAt: 0,
        );
        $restoredZero = NotificationCreatedSignalData::fromArray($zero->toArray());
        self::assertSame(0, $restoredZero->readAt);
        self::assertSame(0, $restoredZero->createdAt);
    }

    public function testCreatedSignalDataRefusesSqlStrings(): void
    {
        $base = [
            NotificationCreatedSignalData::id => 1,
            NotificationCreatedSignalData::userId => 2,
            NotificationCreatedSignalData::type => 'test',
            NotificationCreatedSignalData::severity => NotificationSeverity::INFO,
            NotificationCreatedSignalData::title => 'Title',
        ];

        try {
            NotificationCreatedSignalData::fromArray([...$base, NotificationCreatedSignalData::createdAt => '2026-07-27 10:00:00']);
            self::fail('Expected InvalidFormatException for SQL string createdAt');
        } catch (InvalidFormatException $e) {
            self::assertStringContainsString(NotificationCreatedSignalData::createdAt, $e->getMessage());
        }

        try {
            NotificationCreatedSignalData::fromArray([...$base, NotificationCreatedSignalData::readAt => '2026-07-27 10:00:00']);
            self::fail('Expected InvalidFormatException for SQL string readAt');
        } catch (InvalidFormatException $e) {
            self::assertStringContainsString(NotificationCreatedSignalData::readAt, $e->getMessage());
        }
    }

    public function testSnapshotSignalDataRoundTripsThroughSnapshotDto(): void
    {
        $row = new NotificationCreatedSignalData(
            id: 10,
            userId: 11,
            type: 'alert',
            severity: NotificationSeverity::WARNING,
            title: 'Notice',
            body: 'Details',
            data: null,
            readAt: null,
            createdAt: 1785060000000,
        );
        $snapshot = new NotificationsSnapshotSignalData([$row], 1);
        $restored = NotificationsSnapshotSignalData::fromArray($snapshot->toArray());

        self::assertSame(1, $restored->unreadCount);
        self::assertCount(1, $restored->recent);
        self::assertSame(10, $restored->recent[0]->id);
        self::assertSame(1785060000000, $restored->recent[0]->createdAt);
        self::assertNull($restored->recent[0]->readAt);
    }

    public function testSnapshotProducerAndLiveProducerYieldIdenticalRows(): void
    {
        $entity = EntityNotification::getEmpty();
        $entity->id = 55;
        $entity->user_id = 99;
        $entity->type = 'deploy.completed';
        $entity->severity = NotificationSeverity::SUCCESS;
        $entity->title = 'Deploy ready';
        $entity->body = 'Deploy finished successfully';
        $entity->data = '{"version":"1.2.3"}';
        $entity->read_at = '2026-07-27 10:15:30';
        $entity->created_at = '2026-07-27 10:00:00';
        $notification = ObjectNotification::fromEntity($entity);

        $groupProducer = new class extends AbstractHilosNotificationsGroup {
            public function __construct()
            {
            }

            public function produceRow(ObjectNotification $notification): NotificationCreatedSignalData
            {
                return $this->rowFor($notification);
            }
        };

        $liveProducer = new class extends AbstractNotificationsLibraryAgent {
            public function __construct()
            {
            }

            public function produceRow(ObjectNotification $notification): NotificationCreatedSignalData
            {
                return $this->rowFor($notification);
            }
        };

        $snapshotRow = $groupProducer->produceRow($notification);
        $liveRow = $liveProducer->produceRow($notification);

        self::assertEquals($snapshotRow, $liveRow);
        self::assertSame($snapshotRow->toArray(), $liveRow->toArray());
        self::assertSame(TimeHelper::sqlToMs('2026-07-27 10:00:00'), $snapshotRow->createdAt);
        self::assertSame(TimeHelper::sqlToMs('2026-07-27 10:15:30'), $snapshotRow->readAt);
        self::assertSame($snapshotRow->createdAt, $liveRow->createdAt);
        self::assertSame($snapshotRow->readAt, $liveRow->readAt);

        // Also verify unread row (read_at is null)
        $entityUnread = EntityNotification::getEmpty();
        $entityUnread->id = 56;
        $entityUnread->user_id = 99;
        $entityUnread->type = 'deploy.completed';
        $entityUnread->severity = NotificationSeverity::SUCCESS;
        $entityUnread->title = 'Deploy unread';
        $entityUnread->body = null;
        $entityUnread->data = null;
        $entityUnread->read_at = null;
        $entityUnread->created_at = '2026-07-27 10:00:00';
        $notificationUnread = ObjectNotification::fromEntity($entityUnread);

        $snapshotUnread = $groupProducer->produceRow($notificationUnread);
        $liveUnread = $liveProducer->produceRow($notificationUnread);

        self::assertEquals($snapshotUnread, $liveUnread);
        self::assertNull($snapshotUnread->readAt);
        self::assertNull($liveUnread->readAt);
    }

    public function testReadSignalDataCarriesSingleIdAndAllSentinel(): void
    {
        $one = NotificationReadSignalData::one(9);
        self::assertSame(9, $one->id);
        self::assertSame(9, NotificationReadSignalData::fromArray($one->toArray())->id);

        $all = NotificationReadSignalData::all();
        self::assertSame(NotificationReadSignalData::ALL, $all->id);
        self::assertSame(
            NotificationReadSignalData::ALL,
            NotificationReadSignalData::fromArray($all->toArray())->id,
        );
    }

    public function testSignalNamesAreStableAndDistinct(): void
    {
        self::assertSame('notification_created', NotificationSignalName::CREATED);
        self::assertSame('notification_read', NotificationSignalName::READ);
        self::assertNotSame(NotificationSignalName::CREATED, NotificationSignalName::READ);
    }

    public function testMarkReadPayloadParsesIdAndUnwrapsEnvelope(): void
    {
        $direct = NotificationMarkReadPayloadDTO::fromArray([NotificationMarkReadPayloadDTO::id => 12]);
        self::assertSame(12, $direct->id);
        self::assertSame(NotificationAction::MARK_READ, $direct->getAction());

        $enveloped = NotificationMarkReadPayloadDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [NotificationMarkReadPayloadDTO::id => 34],
        ]);
        self::assertSame(34, $enveloped->id);
    }

    public function testMarkReadPayloadNamingNoNotificationIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(NotificationMarkReadPayloadDTO::id);

        NotificationMarkReadPayloadDTO::fromArray([]);
    }

    public function testMarkReadPayloadWithANonObjectSectionIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('data section');

        NotificationMarkReadPayloadDTO::fromArray([SignalPayloadConstants::FIELD_DATA => 'nope']);
    }

    public function testMarkAllReadPayloadCarriesNoFields(): void
    {
        $dto = NotificationMarkAllReadPayloadDTO::fromArray([SignalPayloadConstants::FIELD_DATA => ['ignored' => 1]]);

        self::assertSame(NotificationAction::MARK_ALL_READ, $dto->getAction());
        self::assertSame([], $dto->toArray());
    }

}
