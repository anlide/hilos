<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\DTO\DeferredSessionCarryoverHandoverSignalData;
use Hilos\Auth\Session\SessionCarryover;
use Hilos\Auth\Session\SessionIdentityRef;
use Hilos\Backup\Agent\DTO\DeferredNoticesSentSignalData;
use Hilos\Backup\Agent\DTO\DeferredSessionsCarriedSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Notification\DTO\DeferredNotificationHandoverSignalData;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Round trips of the four frames a deferred restore batch travels in (HIL-846).
 *
 * The hand-overs and their receipts cross the worker link and, when the library is placed on another
 * node, the peer link, so each one is read back from what the other side received rather than from
 * the object that was sent. A key spelled differently on the two sides does not fail where it is
 * written: it fails on the receiving node as a payload that is not the one its name promises, and
 * the batch is offered forever. These cases are where that shows up instead.
 */
final class DeferredRestoreHandoverSignalDataTest extends TestCase
{
    /** @var string Batch id the fixtures travel under, in the shape a queue mints */
    private const string BATCH = '0a1b2c3d';

    /**
     * @throws JsonException When a fixture payload cannot be encoded or decoded
     */
    public function testTheSessionHandOverComesBackWhole(): void
    {
        $sent = new DeferredSessionCarryoverHandoverSignalData(self::BATCH, [
            new SessionCarryover(
                token: 'token-a',
                createdAt: '2026-09-12 10:00:00',
                expiresAt: '2026-10-12 10:00:00',
                identities: [new SessionIdentityRef('email', 'person@example.com')],
            ),
            new SessionCarryover('token-b', '2026-09-12 11:00:00', null, []),
        ]);

        $received = DeferredSessionCarryoverHandoverSignalData::fromArray(self::overTheWire($sent->toArray()));

        self::assertSame(self::BATCH, $received->batch);
        self::assertCount(2, $received->sessions);
        self::assertSame('token-a', $received->sessions[0]->token);
        self::assertSame('2026-09-12 10:00:00', $received->sessions[0]->createdAt);
        self::assertSame('2026-10-12 10:00:00', $received->sessions[0]->expiresAt);
        self::assertSame('email', $received->sessions[0]->identities[0]->type);
        self::assertSame('person@example.com', $received->sessions[0]->identities[0]->identifier);
        self::assertNull($received->sessions[1]->expiresAt, 'A login with no expiry does not gain one on the way');
    }

    public function testASessionHandOverWithoutABatchIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        DeferredSessionCarryoverHandoverSignalData::fromArray([DeferredSessionCarryoverHandoverSignalData::sessions => []]);
    }

    public function testASessionHandOverCarryingSomethingThatIsNotASessionIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        DeferredSessionCarryoverHandoverSignalData::fromArray([
            DeferredSessionCarryoverHandoverSignalData::batch => self::BATCH,
            DeferredSessionCarryoverHandoverSignalData::sessions => ['token-a'],
        ]);
    }

    /**
     * @throws JsonException When a fixture payload cannot be encoded or decoded
     */
    public function testTheNotificationHandOverComesBackWhole(): void
    {
        $sent = new DeferredNotificationHandoverSignalData(self::BATCH, [
            new NotificationDraft(
                userId: 7,
                type: 'backup.restore.failed',
                title: 'Restore failed',
                severity: NotificationSeverity::ERROR,
                body: 'the body',
                data: ['backupId' => 'b-1'],
            ),
        ]);

        $received = DeferredNotificationHandoverSignalData::fromArray(self::overTheWire($sent->toArray()));

        self::assertSame(self::BATCH, $received->batch);
        self::assertCount(1, $received->notifications);
        self::assertSame(7, $received->notifications[0]->userId);
        self::assertSame('backup.restore.failed', $received->notifications[0]->type);
        self::assertSame('Restore failed', $received->notifications[0]->title);
        self::assertSame(NotificationSeverity::ERROR, $received->notifications[0]->severity);
        self::assertSame('the body', $received->notifications[0]->body);
        self::assertSame(['backupId' => 'b-1'], $received->notifications[0]->data);
    }

    public function testANotificationHandOverCarryingSomethingThatIsNotANoticeIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        DeferredNotificationHandoverSignalData::fromArray([
            DeferredNotificationHandoverSignalData::batch => self::BATCH,
            DeferredNotificationHandoverSignalData::notifications => ['a letter'],
        ]);
    }

    /**
     * @throws JsonException When a fixture payload cannot be encoded or decoded
     */
    public function testTheSessionReceiptComesBackWhole(): void
    {
        $sent = new DeferredSessionsCarriedSignalData(self::BATCH, 3, 1, 2);

        $received = DeferredSessionsCarriedSignalData::fromArray(self::overTheWire($sent->toArray()));

        self::assertSame(self::BATCH, $received->batch);
        self::assertSame(3, $received->carried);
        self::assertSame(1, $received->dropped);
        self::assertSame(2, $received->kept);
    }

    public function testASessionReceiptMissingACountIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        DeferredSessionsCarriedSignalData::fromArray([
            DeferredSessionsCarriedSignalData::batch => self::BATCH,
            DeferredSessionsCarriedSignalData::carried => 3,
            DeferredSessionsCarriedSignalData::dropped => 1,
        ]);
    }

    /**
     * @throws JsonException When a fixture payload cannot be encoded or decoded
     */
    public function testTheNoticeReceiptComesBackWhole(): void
    {
        $sent = new DeferredNoticesSentSignalData(self::BATCH, 1, 0);

        $received = DeferredNoticesSentSignalData::fromArray(self::overTheWire($sent->toArray()));

        self::assertSame(self::BATCH, $received->batch);
        self::assertSame(1, $received->sent);
        self::assertSame(0, $received->dropped);
    }

    public function testANoticeReceiptWithoutABatchIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        DeferredNoticesSentSignalData::fromArray([
            DeferredNoticesSentSignalData::sent => 1,
            DeferredNoticesSentSignalData::dropped => 0,
        ]);
    }

    /**
     * Sends a payload the way the links do: as JSON text, decoded again on the other side.
     *
     * @param array<string, mixed> $payload What the sender's toArray() produced
     * @return array<string, mixed> What the receiver's fromArray() is given
     * @throws JsonException When the payload cannot be encoded or decoded
     */
    private static function overTheWire(array $payload): array
    {
        $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
