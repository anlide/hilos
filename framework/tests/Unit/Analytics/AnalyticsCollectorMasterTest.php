<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/** The master's memory and journal records, with no database behind its connection path. */
final class AnalyticsCollectorMasterTest extends TestCase
{
    private const string ACCEPT_KEY = 'master-accept-key';

    /** Installs an empty router and unheld runtime for the collector. */
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
    }

    /** Clears the facades written by this case. */
    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;
        parent::tearDown();
    }

    /** A connection's page and action keys remain coherent through navigation and shutdown. */
    public function testPageChangesActionAndShutdownClosuresTravelAsOneBatch(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWsConnection(self::ACCEPT_KEY, '127.0.0.1');
        $collector->openPageSession(self::ACCEPT_KEY, 'first', ['room' => 1]);
        $collector->openPageSession(self::ACCEPT_KEY, 'second', ['room' => 2]);
        $collector->updatePageSession(self::ACCEPT_KEY, ['room' => 3]);
        $actionKey = $collector->logUserAction(self::ACCEPT_KEY, 'send', ['body' => 'hi']);
        $this->assertNotNull($actionKey);
        $collector->closeOpenConnections();

        $records = $this->records();
        $this->assertSame([
            AnalyticsJournalRecord::TYPE_WS_CONNECTION_OPEN,
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_OPEN,
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_CLOSE,
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_OPEN,
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_UPDATE,
            AnalyticsJournalRecord::TYPE_USER_ACTION,
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_CLOSE,
            AnalyticsJournalRecord::TYPE_WS_CONNECTION_CLOSE,
        ], array_column($records, AnalyticsJournalRecord::KEY_TYPE));
        $this->assertSame($records[3]['key'], $records[5]['pageKey']);
        $this->assertSame($actionKey, $records[5]['key']);
        $this->assertSame($records[3]['key'], $records[6]['key']);
        $this->assertSame('127.0.0.1', $records[0]['ip']);
        $this->assertNull($collector->logUserAction(self::ACCEPT_KEY, 'send', null));
    }

    /** A parked request has no active cause until its synchronous handler is running. */
    public function testOnlySynchronousCapturesPutKeysInSignalMeta(): void
    {
        $collector = new AnalyticsCollector();
        $request = $collector->startApiRequest(null, 'GET', '/health', null, null, null);
        $this->assertSame([], $collector->captureSignalMeta());
        $collector->startApiRequestCapture($request);
        $this->assertSame([AnalyticsCollector::META_API_REQUEST_KEY => $request->key], $collector->captureSignalMeta());
        $collector->clearApiRequestCapture();
        $this->assertSame([], $collector->captureSignalMeta());
        $collector->finishApiRequest($request, 200, 12);
        $collector->flush();

        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame($request->key, $records[0]['key']);
        $this->assertSame(200, $records[0]['status']);
        $this->assertSame(12, $records[0]['durationMs']);
    }

    /** A number or malformed string from an older signal envelope is no cause key. */
    public function testOnlyAValidHexKeyIsReadFromSignalMeta(): void
    {
        $collector = new AnalyticsCollector();
        $valid = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'chat'),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('message'),
            new SignalData(),
            [AnalyticsCollector::META_API_REQUEST_KEY => 42, AnalyticsCollector::META_USER_ACTION_KEY => $valid],
        );
        $this->assertNull($collector->getSignalMetaKey($signal, AnalyticsCollector::META_API_REQUEST_KEY));
        $this->assertSame($valid, $collector->getSignalMetaKey($signal, AnalyticsCollector::META_USER_ACTION_KEY));
    }

    /** The freeze discards records but preserves memory until a database replacement. */
    public function testFreezeKeepsTheConnectionMapAndRestoreForgetsIt(): void
    {
        $collector = new AnalyticsCollector();
        Hilos::$rt = new AnalyticsCollectorMasterTestRtContext();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]));
        $collector->openWsConnection(self::ACCEPT_KEY, null);
        $collector->flush();
        $this->assertSame([], $this->records());

        Hilos::$rt = null;
        $this->assertNotNull($collector->logUserAction(self::ACCEPT_KEY, 'send', null));
        $collector->flush();
        $this->assertSame([AnalyticsJournalRecord::TYPE_USER_ACTION],
            array_column($this->records(), AnalyticsJournalRecord::KEY_TYPE));

        $collector->forgetReplacedDatabase();
        $this->assertNull($collector->logUserAction(self::ACCEPT_KEY, 'send', null));
    }

    /**
     * @return list<array<string, mixed>> Records sent to the node's journal agent
     */
    private function records(): array
    {
        $records = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertSame(HilosSignalConstants::ANALYTICS_JOURNAL_APPEND, $signal->signalName->getName());
            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            $batch = $signal->data->data;
            $this->assertInstanceOf(AnalyticsJournalAppendSignalData::class, $batch);
            foreach ($batch->lines as $line) {
                $records[] = (array)json_decode($line, true);
            }
        }
        return $records;
    }
}

/** A context with only the framework protected-mode row for this collector test. */
final class AnalyticsCollectorMasterTestRtContext extends RtContext
{
    /** No project state is needed by this test. */
    public function configure(): void
    {
    }
}
