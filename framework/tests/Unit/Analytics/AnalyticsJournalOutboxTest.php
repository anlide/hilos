<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalOutbox;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\AnalyticsLossReason;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/**
 * The source half of the analytics journal (HIL-1154): what a worker's collector hands the journal
 * agent of its node, and when.
 */
final class AnalyticsJournalOutboxTest extends TestCase
{
    private const int T0 = 1_800_000_000_000;

    private const string AGENT_TYPE = 'hil_1154_agent';

    private const int WORKER_INDEX = 2;

    private const string USER_ACTION_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string API_REQUEST_KEY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testOversizedPayloadIsRemovedAndOversizedRecordIsDropped(): void
    {
        $payload = ['body' => str_repeat('x', AnalyticsJournalRecord::MAX_LINE_BYTES)];
        $record = AnalyticsJournalRecord::agentSystemSignal('agent', 'event', $payload, self::T0);
        $line = AnalyticsJournalRecord::encode($record);
        $this->assertNotNull($line);
        $this->assertNull(json_decode($line, true)[AnalyticsJournalRecord::KEY_PAYLOAD]);

        $tooLong = AnalyticsJournalRecord::workerSystemSignal('worker', str_repeat('x', AnalyticsJournalRecord::MAX_LINE_BYTES), null, self::T0);
        $this->assertNull(AnalyticsJournalRecord::encode($tooLong));

        $outbox = new AnalyticsJournalOutbox(self::T0);
        $outbox->add($record, [], self::T0);
        $outbox->add($tooLong, [], self::T0 + 1);
        $outbox->flush(self::T0 + 2);
        $frame = $this->frames()[0];
        $this->assertSame(1, $frame->events);
        $this->assertCount(1, $frame->lines);
        $this->assertSame([
            ['reason' => AnalyticsLossReason::PAYLOAD_DROPPED->value, 'events' => 1, 'fromTs' => self::T0, 'toTs' => self::T0],
            ['reason' => AnalyticsLossReason::RECORD_DROPPED->value, 'events' => 1, 'fromTs' => self::T0 + 1, 'toTs' => self::T0 + 1],
        ], array_map(static fn($loss): array => $loss->toArray(), $frame->losses));
    }

    public function testLossCountsSurviveClearingLinesAndLeaveInAnEmptyBatch(): void
    {
        $outbox = new AnalyticsJournalOutbox(self::T0);
        $outbox->add(AnalyticsJournalRecord::browserSessionRename('a', 'b', self::T0), [], self::T0);
        $outbox->countLoss(AnalyticsLossReason::RESTORE, 2, self::T0, self::T0 + 1);
        $this->assertSame(1, $outbox->clear());
        $outbox->countLoss(AnalyticsLossReason::RESTORE, 1, self::T0 + 2, self::T0 + 2);
        $outbox->flush(self::T0 + 3);

        $frame = $this->frames()[0];
        $this->assertSame([], $frame->lines);
        $this->assertSame(0, $frame->events);
        $this->assertSame(['reason' => 'restore', 'events' => 3, 'fromTs' => self::T0, 'toTs' => self::T0 + 2], $frame->losses[0]->toArray());
    }

    /**
     * Every method a worker calls builds its record by the catalog, key for key.
     */
    public function testEveryWorkerMethodBuildsItsRecordByTheCatalog(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, true);
        $collector->openAgentSession(self::AGENT_TYPE, '4');
        $collector->logAgentUserAction(self::AGENT_TYPE, '4', self::USER_ACTION_KEY, 'hil_1154_action', ['a' => 1]);
        $collector->logAgentSystemSignal(self::AGENT_TYPE, '4', 'hil_1154_system', ['b' => 2]);
        $collector->logAgentCronSignal(self::AGENT_TYPE, '4', 'hil_1154_cron', null);
        $collector->logWorkerSystemSignal('hil_1154_worker', ['c' => 3]);
        $collector->logApiAgentAction(self::API_REQUEST_KEY, self::AGENT_TYPE, '4', 'hil_1154_api', ['d' => 4]);
        $collector->attachWsConnectionToBrowserSession('ak', 'token-a', 'UA', 'en');
        $collector->identifyBrowserSessionUser('token-a', 42);
        $collector->closeAgentSession(self::AGENT_TYPE, '4');
        $collector->closeWorkerSession();
        // Last, because a rename sends the batch at once.
        $collector->renameBrowserSession('token-a', 'token-b');

        $records = $this->batches()[0];
        $this->assertSame([
            [AnalyticsJournalRecord::TYPE_WORKER_SESSION, ['t', 'key', 'workerIndex', 'monopolistic', 'startedTs']],
            [AnalyticsJournalRecord::TYPE_AGENT_SESSION, ['t', 'key', 'workerKey', 'agentType', 'agentIndex', 'startedTs']],
            [AnalyticsJournalRecord::TYPE_AGENT_USER_ACTION, ['t', 'agentKey', 'userActionKey', 'signal', 'payload', 'ts']],
            [AnalyticsJournalRecord::TYPE_AGENT_SYSTEM_SIGNAL, ['t', 'agentKey', 'signal', 'payload', 'ts']],
            [AnalyticsJournalRecord::TYPE_AGENT_CRON_SIGNAL, ['t', 'agentKey', 'cron', 'payload', 'ts']],
            [AnalyticsJournalRecord::TYPE_WORKER_SYSTEM_SIGNAL, ['t', 'workerKey', 'signal', 'payload', 'ts']],
            [AnalyticsJournalRecord::TYPE_API_AGENT_ACTION, ['t', 'apiRequestKey', 'agentKey', 'signal', 'payload', 'ts']],
            [AnalyticsJournalRecord::TYPE_WS_CONNECTION_ATTACH, ['t', 'acceptKey', 'sessionToken', 'userAgent', 'acceptLanguage', 'ts']],
            [AnalyticsJournalRecord::TYPE_BROWSER_SESSION_IDENTITY, ['t', 'sessionToken', 'identityType', 'identityValue', 'ts']],
            [AnalyticsJournalRecord::TYPE_AGENT_SESSION_STOP, ['t', 'key', 'ts']],
            [AnalyticsJournalRecord::TYPE_WORKER_SESSION_STOP, ['t', 'key', 'ts']],
            [AnalyticsJournalRecord::TYPE_BROWSER_SESSION_RENAME, ['t', 'oldToken', 'newToken', 'ts']],
        ], array_map(static fn(array $record): array => [$record['t'], array_keys($record)], $records));

        [$worker, $agent, $userAction, $system] = $records;
        $this->assertMatchesRegularExpression(AnalyticsJournalRecord::SESSION_KEY_PATTERN, $worker['key']);
        $this->assertSame([self::WORKER_INDEX, true], [$worker['workerIndex'], $worker['monopolistic']]);
        $this->assertSame([$worker['key'], self::AGENT_TYPE, '4'], [$agent['workerKey'], $agent['agentType'], $agent['agentIndex']]);
        $this->assertSame([$agent['key'], self::USER_ACTION_KEY], [$userAction['agentKey'], $userAction['userActionKey']]);
        // An action the topology does not know keeps its name and loses its payload (HIL-1187).
        $this->assertNull($userAction['payload']);
        $this->assertSame(['b' => 2], $system['payload']);
        $this->assertSame(self::API_REQUEST_KEY, $records[6]['apiRequestKey']);
        $this->assertSame(['user_id', '42'], [$records[8]['identityType'], $records[8]['identityValue']]);
        $this->assertSame([$agent['key'], $worker['key']], [$records[9]['key'], $records[10]['key']]);
    }

    /**
     * A batch stands on its own: the second one names the same sessions again, ahead of its records.
     */
    public function testEveryBatchOpensWithTheDescriptionsOfTheSessionsItNames(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);
        $collector->openAgentSession(self::AGENT_TYPE, null);
        $collector->flush();
        $collector->logAgentSystemSignal(self::AGENT_TYPE, null, 'hil_1154_system', null);
        $collector->flush();
        $collector->renameBrowserSession('token-a', 'token-b');
        $collector->flush();

        $this->assertSame([
            [AnalyticsJournalRecord::TYPE_WORKER_SESSION, AnalyticsJournalRecord::TYPE_AGENT_SESSION],
            [
                AnalyticsJournalRecord::TYPE_WORKER_SESSION,
                AnalyticsJournalRecord::TYPE_AGENT_SESSION,
                AnalyticsJournalRecord::TYPE_AGENT_SYSTEM_SIGNAL,
            ],
            [AnalyticsJournalRecord::TYPE_BROWSER_SESSION_RENAME],
        ], array_map(
            static fn(array $batch): array => array_map(static fn(array $record): string => $record['t'], $batch),
            $this->batches(),
        ));
    }

    /**
     * A rename leaves at once: a reconnect served by another worker records the new token next,
     * and the rename must reach the journal ahead of it.
     */
    public function testARenameLeavesWithoutWaitingForTheSecond(): void
    {
        $collector = new AnalyticsCollector();
        $collector->renameBrowserSession('token-a', 'token-b');

        $this->assertSame([[AnalyticsJournalRecord::TYPE_BROWSER_SESSION_RENAME]], array_map(
            static fn(array $batch): array => array_column($batch, 't'),
            $this->batches(),
        ));
    }

    /**
     * @throws InvalidArgumentException When a batch cannot be named
     */
    public function testABatchLeavesOnceASecond(): void
    {
        $outbox = new AnalyticsJournalOutbox(self::T0);
        $outbox->add(AnalyticsJournalRecord::browserSessionRename('a', 'b', self::T0), [], self::T0);

        $outbox->flushIfDue(self::T0 + AnalyticsJournalOutbox::FLUSH_INTERVAL_MS - 1);
        $this->assertSame([], $this->batches());

        $outbox->flushIfDue(self::T0 + AnalyticsJournalOutbox::FLUSH_INTERVAL_MS);
        $this->assertCount(1, $this->batches());

        $outbox->flushIfDue(self::T0 + 2 * AnalyticsJournalOutbox::FLUSH_INTERVAL_MS);
        $this->assertSame([], $this->batches());
    }

    /**
     * @throws InvalidArgumentException When a batch cannot be named
     */
    public function testABatchLeavesAtSixtyFourKibibytesWithoutWaitingForTheSecond(): void
    {
        $outbox = new AnalyticsJournalOutbox(self::T0);
        $token = str_repeat('t', 1000);

        $added = 0;
        $signal = null;
        while ($signal === null) {
            $outbox->add(AnalyticsJournalRecord::browserSessionRename($token, $token, self::T0), [], self::T0);
            $added++;
            $signal = Hilos::$sr?->getNextQueuedSignal();
        }

        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(AnalyticsJournalAppendSignalData::class, $signal->data->data);
        $lines = $signal->data->data->lines;
        $this->assertCount($added, $lines);
        $this->assertGreaterThanOrEqual(AnalyticsJournalOutbox::FLUSH_BYTES, strlen(implode('', $lines)));
        $this->assertLessThan(AnalyticsJournalOutbox::FLUSH_BYTES, strlen(implode('', array_slice($lines, 1))));
    }

    public function testUnderTheFreezeNothingIsRecordedAndTheGatheredBatchIsThrownAway(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);
        $collector->logWorkerSystemSignal('hil_1154_before', null);

        $this->freeze(ProtectedModeRuntime::PHASE_ACTIVATING);
        $collector->logWorkerSystemSignal('hil_1154_under', null);
        $collector->flush();
        $this->assertSame([], $this->batches());

        $this->freeze(ProtectedModeRuntime::PHASE_VERIFYING);
        $collector->logWorkerSystemSignal('hil_1154_after', null);
        $collector->flush();
        $frames = $this->frames();
        $this->assertCount(1, $frames);
        $this->assertSame(1, $frames[0]->events);
        $this->assertSame(['reason' => 'restore', 'events' => 2], [
            'reason' => $frames[0]->losses[0]->reason->value,
            'events' => $frames[0]->losses[0]->events,
        ]);
        $records = array_map(static fn(string $line): array => (array)json_decode($line, true), $frames[0]->lines);
        $this->assertSame(
            [AnalyticsJournalRecord::TYPE_WORKER_SESSION, AnalyticsJournalRecord::TYPE_WORKER_SYSTEM_SIGNAL],
            array_column($records, 't'),
        );
        $this->assertSame('hil_1154_after', $records[1]['signal']);
    }

    public function testAStopUnderTheFreezeLeavesAfterItWithItsOwnMoment(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);
        $collector->openAgentSession(self::AGENT_TYPE, null);
        $collector->flush();
        $this->batches();
        $collector->logWorkerSystemSignal('hil_1157_pending', null);

        $this->freeze(ProtectedModeRuntime::PHASE_ACTIVE);
        $before = (int)floor(microtime(true) * 1000);
        $collector->closeAgentSession(self::AGENT_TYPE, null);
        $after = (int)floor(microtime(true) * 1000);
        $collector->flush();
        $this->assertSame([], $this->batches());

        usleep(20_000);
        $this->freeze(ProtectedModeRuntime::PHASE_VERIFYING);
        $collector->tick();
        $collector->flush();

        $frames = $this->frames();
        $this->assertCount(1, $frames);
        $this->assertSame(1, $frames[0]->events);
        $this->assertSame(1, $frames[0]->losses[0]->events);
        $this->assertSame(AnalyticsLossReason::RESTORE, $frames[0]->losses[0]->reason);
        $batches = [array_map(static fn(string $line): array => (array)json_decode($line, true), $frames[0]->lines)];
        $this->assertSame([
            AnalyticsJournalRecord::TYPE_WORKER_SESSION,
            AnalyticsJournalRecord::TYPE_AGENT_SESSION,
            AnalyticsJournalRecord::TYPE_AGENT_SESSION_STOP,
        ], array_column($batches[0], 't'));
        $this->assertGreaterThanOrEqual($before, $batches[0][2]['ts']);
        $this->assertLessThanOrEqual($after, $batches[0][2]['ts']);
    }

    public function testTheJournalDoesNotRecordItsOwnAgents(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, true);
        foreach ([HilosAgentType::HILOS_ANALYTICS_JOURNAL, HilosAgentType::HILOS_ANALYTICS_WRITER] as $type) {
            $collector->openAgentSession($type, null);
            $collector->logAgentSystemSignal($type, null, 'hil_1154_system', null);
            $collector->logApiAgentAction(self::API_REQUEST_KEY, $type, null, HilosSignalConstants::ANALYTICS_JOURNAL_APPEND, null);
            $collector->closeAgentSession($type, null);
        }
        $collector->flush();

        $this->assertSame([[AnalyticsJournalRecord::TYPE_WORKER_SESSION]], array_map(
            static fn(array $batch): array => array_column($batch, 't'),
            $this->batches(),
        ));
    }

    /**
     * Takes every queued batch off the router.
     *
     * @return list<list<array<string, mixed>>> Each batch as its decoded records
     */
    private function batches(): array
    {
        return array_map(
            static fn(AnalyticsJournalAppendSignalData $batch): array => array_map(
                static fn(string $line): array => (array)json_decode($line, true),
                $batch->lines,
            ),
            $this->frames(),
        );
    }

    /**
     * @return list<AnalyticsJournalAppendSignalData> Frames queued for the journal agent
     */
    private function frames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertSame(HilosSignalConstants::ANALYTICS_JOURNAL_APPEND, $signal->signalName->getName());
            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            $batch = $signal->data->data;
            $this->assertInstanceOf(AnalyticsJournalAppendSignalData::class, $batch);
            $frames[] = $batch;
        }

        return $frames;
    }

    /**
     * @param string $phase Freeze phase to mount
     */
    private function freeze(string $phase): void
    {
        Hilos::$rt = new AnalyticsJournalOutboxTestRtContext();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => $phase,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]));
    }
}

/**
 * Runtime context that registers no project state: the framework mount supplies the freeze row.
 */
final class AnalyticsJournalOutboxTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
