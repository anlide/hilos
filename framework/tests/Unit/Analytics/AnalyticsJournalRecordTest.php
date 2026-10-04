<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\AnalyticsLossCount;
use Hilos\Core\Analytics\AnalyticsLossReason;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/** The record, loss-count and signal contracts used across analytics processes. */
final class AnalyticsJournalRecordTest extends TestCase
{
    public function testEventEncodingReportsTheLossOfPayloadAndTheLossOfAWholeRecord(): void
    {
        $payload = ['body' => str_repeat('x', AnalyticsJournalRecord::MAX_LINE_BYTES)];
        $withPayload = AnalyticsJournalRecord::agentSystemSignal('agent', 'event', $payload, 1);
        $encoded = AnalyticsJournalRecord::encodeEvent($withPayload);
        self::assertTrue($encoded->payloadDropped);
        self::assertNotNull($encoded->line);
        self::assertNull(json_decode($encoded->line, true)[AnalyticsJournalRecord::KEY_PAYLOAD]);

        $tooLong = AnalyticsJournalRecord::workerSystemSignal('worker', str_repeat('x', AnalyticsJournalRecord::MAX_LINE_BYTES), null, 2);
        self::assertNull(AnalyticsJournalRecord::encodeEvent($tooLong)->line);
        self::assertFalse(AnalyticsJournalRecord::encodeEvent($tooLong)->payloadDropped);
    }

    public function testOnlyEventRecordsCountAsEvents(): void
    {
        foreach ([
            AnalyticsJournalRecord::TYPE_JOURNAL,
            AnalyticsJournalRecord::TYPE_JOURNAL_END,
            AnalyticsJournalRecord::TYPE_WORKER_SESSION,
            AnalyticsJournalRecord::TYPE_AGENT_SESSION,
            AnalyticsJournalRecord::TYPE_LOSS,
        ] as $type) {
            self::assertFalse(AnalyticsJournalRecord::isEvent($type), $type);
        }

        self::assertTrue(AnalyticsJournalRecord::isEvent(AnalyticsJournalRecord::TYPE_WORKER_SESSION_STOP));
        self::assertTrue(AnalyticsJournalRecord::isEvent(AnalyticsJournalRecord::TYPE_AGENT_SYSTEM_SIGNAL));
        self::assertTrue(AnalyticsJournalRecord::isEvent(AnalyticsJournalRecord::TYPE_WS_CONNECTION_OPEN));
    }

    public function testLossAndEndRecordsHaveTheNamedWireFields(): void
    {
        self::assertSame(
            ['t' => 'loss', 'reason' => 'restore', 'events' => 3, 'fromTs' => 10, 'toTs' => 20],
            AnalyticsJournalRecord::loss(AnalyticsLossReason::RESTORE, 3, 10, 20),
        );
        self::assertSame(['t' => 'journal_end', 'events' => 7, 'closedTs' => 30], AnalyticsJournalRecord::journalEnd(7, 30));
    }

    public function testLossCountsAndAppendFramesRoundTripAcrossTheSignalBoundary(): void
    {
        $count = new AnalyticsLossCount(AnalyticsLossReason::PAYLOAD_DROPPED, 2, 10, 20);
        self::assertEquals($count, AnalyticsLossCount::fromArray($count->toArray()));
        self::assertSame(AnalyticsJournalRecord::loss(AnalyticsLossReason::PAYLOAD_DROPPED, 2, 10, 20), json_decode($count->toLine(), true));

        $frame = new AnalyticsJournalAppendSignalData(['line'], 1, [$count]);
        self::assertEquals($frame, AnalyticsJournalAppendSignalData::fromArray($frame->toArray()));

        $portion = new AnalyticsJournalPortionSignalData('node', 'file', 0, 5, ['line'], true, false, 3);
        self::assertEquals($portion, AnalyticsJournalPortionSignalData::fromArray($portion->toArray()));
    }

    public function testUnknownLossReasonIsRefusedAtTheBoundary(): void
    {
        $this->expectException(InvalidFormatException::class);
        AnalyticsLossCount::fromArray(['reason' => 'other', 'events' => 1, 'fromTs' => 10, 'toTs' => 20]);
    }
}
