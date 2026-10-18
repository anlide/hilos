<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Exception\UnsupportedOperationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\TestCase;

/** Page analytics follows raw connection frames before the master's signal queue is drained. */
final class WebSocketClientPageAnalyticsTest extends TestCase
{
    private const int FINAL_TEXT_FRAME = 0x81;
    private const int MAX_SHORT_PAYLOAD_BYTES = 125;

    private ?SignalRouter $previousSignalRouter = null;
    private ?EnvAccessor $previousEnv = null;
    private ?AnalyticsCollector $previousCollector = null;

    /** Installs the collector before the handshake opens its connection. */
    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        $this->previousCollector = Hilos::$ac;
        Hilos::$sr = new SignalRouter();
        Hilos::$env = new EnvAccessor();
        Hilos::$ac = new AnalyticsCollector();
    }

    /** Restores the facades replaced by this case. */
    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        Hilos::$ac = $this->previousCollector;
        parent::tearDown();
    }

    /** A subscribe and action read together share the page session opened by the first frame. */
    public function testSubscribeAndActionInOneReadUseTheOpenedPageSession(): void
    {
        $probe = $this->handshakenProbe();
        $probe->feed(
            $this->textFrame('{"type":"page_subscribe","page":"main","params":{}}')
            . $this->textFrame('{"type":"action","action":"probe_action","data":{}}'),
        );

        $records = $this->records();
        $this->assertSame([
            AnalyticsJournalRecord::TYPE_WS_CONNECTION_OPEN,
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_OPEN,
            AnalyticsJournalRecord::TYPE_USER_ACTION,
        ], array_column($records, AnalyticsJournalRecord::KEY_TYPE));
        $this->assertSame('main', $records[1]['page']);
        $this->assertSame($probe->acceptKey, $records[1]['acceptKey']);
        $this->assertSame([], $records[1]['params']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $records[1]['key']);
        $this->assertSame('probe_action', $records[2]['action']);
        $this->assertSame($records[1]['key'], $records[2]['pageKey']);
    }

    /**
     * A later main subscription does not assign its page session to an earlier action.
     *
     * @throws InvalidStateException When the socketless probe cannot be created
     * @throws SocketException When the handshake or frame bytes are invalid
     * @throws AgentUnknownActionException When a frame names an unaccepted action
     * @throws UnsupportedOperationException When a frame reaches an unsupported signal branch
     */
    public function testActionBeforeFirstSubscribeKeepsNullPageKey(): void
    {
        $probe = $this->handshakenProbe();
        $probe->feed(
            $this->textFrame('{"type":"action","action":"probe_action","data":{}}')
            . $this->textFrame('{"type":"page_subscribe","page":"main","params":{}}'),
        );

        $records = $this->records();
        $this->assertSame([
            AnalyticsJournalRecord::TYPE_WS_CONNECTION_OPEN,
            AnalyticsJournalRecord::TYPE_USER_ACTION,
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_OPEN,
        ], array_column($records, AnalyticsJournalRecord::KEY_TYPE));
        $this->assertSame('probe_action', $records[1]['action']);
        $this->assertSame($probe->acceptKey, $records[1]['acceptKey']);
        $this->assertNull($records[1]['pageKey']);
        $this->assertSame('main', $records[2]['page']);
        $this->assertSame($probe->acceptKey, $records[2]['acceptKey']);
    }

    /** A foreign unsubscribe leaves the action's page intact; its own unsubscribe clears it. */
    public function testOnlyTheOpenPageCanBeUnsubscribed(): void
    {
        $probe = $this->handshakenProbe();
        $probe->feed(
            $this->textFrame('{"type":"page_subscribe","page":"main","params":{}}')
            . $this->textFrame('{"type":"page_unsubscribe","page":"other"}')
            . $this->textFrame('{"type":"action","action":"probe_action","data":{}}')
            . $this->textFrame('{"type":"page_unsubscribe","page":"main"}')
            . $this->textFrame('{"type":"action","action":"probe_action","data":{}}'),
        );

        $records = $this->records();
        $this->assertSame([
            AnalyticsJournalRecord::TYPE_WS_CONNECTION_OPEN,
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_OPEN,
            AnalyticsJournalRecord::TYPE_USER_ACTION,
            AnalyticsJournalRecord::TYPE_PAGE_SESSION_CLOSE,
            AnalyticsJournalRecord::TYPE_USER_ACTION,
        ], array_column($records, AnalyticsJournalRecord::KEY_TYPE));
        $this->assertSame('main', $records[1]['page']);
        $this->assertSame($records[1]['key'], $records[2]['pageKey']);
        $this->assertSame($records[1]['key'], $records[3]['key']);
        $this->assertNull($records[4]['pageKey']);
    }

    /**
     * @return WebSocketClientTestProbe Handshaken connection accepting the probe action
     * @throws InvalidStateException When the socketless probe cannot be created
     * @throws SocketException When the handshake bytes are invalid
     * @throws AgentUnknownActionException When a frame names an unaccepted action
     * @throws UnsupportedOperationException When a frame reaches an unsupported signal branch
     */
    private function handshakenProbe(): WebSocketClientTestProbe
    {
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->acceptedActions = ['probe_action'];
        $key = base64_encode('0123456789abcdef');
        $probe->feed(
            "GET /ws HTTP/1.1\r\n"
            . "Host: localhost:8092\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n",
        );
        $this->assertTrue($probe->handshakeDone());

        return $probe;
    }

    /**
     * @param string $payload JSON payload small enough for the short frame length
     * @return string Final, unmasked text frame for the socketless parser
     */
    private function textFrame(string $payload): string
    {
        $this->assertLessThanOrEqual(self::MAX_SHORT_PAYLOAD_BYTES, strlen($payload));

        return chr(self::FINAL_TEXT_FRAME) . chr(strlen($payload)) . $payload;
    }

    /**
     * @return list<array<string, mixed>> Journal records, excluding queued browser signals
     */
    private function records(): array
    {
        Hilos::$ac->flush();
        $records = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::ANALYTICS_JOURNAL_APPEND) {
                continue;
            }
            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            $batch = $signal->data->data;
            $this->assertInstanceOf(AnalyticsJournalAppendSignalData::class, $batch);
            foreach ($batch->lines as $line) {
                $record = json_decode($line, true);
                $this->assertIsArray($record);
                $records[] = $record;
            }
        }

        return $records;
    }
}
