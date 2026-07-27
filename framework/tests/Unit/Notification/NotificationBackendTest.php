<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Notification\DTO\NotificationCreatedSignalData;
use Hilos\Notification\DTO\NotificationMarkAllReadPayloadDTO;
use Hilos\Notification\DTO\NotificationMarkReadPayloadDTO;
use Hilos\Notification\DTO\NotificationReadSignalData;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationGroup;
use Hilos\Notification\NotificationSeverity;
use Hilos\Notification\NotificationSignalName;
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
            createdAt: '2026-07-27 10:00:00',
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
        self::assertSame('2026-07-27 10:00:00', $restored->createdAt);
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

        $missing = NotificationMarkReadPayloadDTO::fromArray([]);
        self::assertSame(0, $missing->id);
    }

    public function testMarkAllReadPayloadCarriesNoFields(): void
    {
        $dto = NotificationMarkAllReadPayloadDTO::fromArray([SignalPayloadConstants::FIELD_DATA => ['ignored' => 1]]);

        self::assertSame(NotificationAction::MARK_ALL_READ, $dto->getAction());
        self::assertSame([], $dto->toArray());
    }
}
