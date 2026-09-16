<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\AgentLogStream;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\NodeLogIndexDelta;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * When the owner of a log directory reports it to the cluster aggregator, and when it stays quiet
 * (HIL-755).
 *
 * The schedule is driven through {@see LogStoreAgent::pushIndexIfDue()} with the clock handed in,
 * the way the walks are driven through {@see LogStoreAgent::walkStore()}: the tick is only this
 * method's throttle, and a test of a one-minute rule must not take a minute.
 *
 * What is held down here is the shape of the rule rather than its numbers. A node that has nothing
 * to say says nothing, so a busy log does not become a busy network; a change is never lost to the
 * walk that happened to follow it; and a silent node still reports once a minute, so an aggregator
 * that restarted has a picture without waiting for something to happen in the logs.
 */
final class LogIndexPushTest extends TestCase
{
    /** Past the keepalive, so a frame is due with nothing at all to report. */
    private const float PAST_THE_KEEPALIVE_SECONDS = 61.0;

    /** Past the default interval and well short of the keepalive: a frame is due only on a change. */
    private const float PAST_THE_DEFAULT_INTERVAL_SECONDS = 5.5;

    /** Short of the default interval, where a change is not yet worth a frame. */
    private const float INSIDE_THE_DEFAULT_INTERVAL_SECONDS = 4.5;

    private string $dir = '';

    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    private ?SettingsAccessor $previousSettings = null;

    /** @var float Instant the agent under test sent its start frame, the origin every offset is measured from */
    private float $startedAt = 0.0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logindex-push-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
        // Outside the fixture on purpose: the agent logs into the very directory it measures.
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-logindex-push-journal');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousSettings = Hilos::$setting;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dir . '/daemon.log');
        Hilos::$sr = new SignalRouter();
        // No settings row by default: that is the ordinary installation, where the interval comes from
        // the environment beneath the settings, and this environment names none.
        LogSettingsResolverTestAccessor::$values = [];
        Hilos::$setting = new LogSettingsResolverTestAccessor(LogSettingsCatalog::class);
    }

    protected function tearDown(): void
    {
        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        putenv(EnvConstants::LOG_INDEX_PUSH_INTERVAL_MS->name);
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        Hilos::$setting = $this->previousSettings;
        LogSettingsResolverTestAccessor::$values = [];
        Hilos::$sr = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        $agentErrorLog = dirname($this->logFile) . '/agent-' . LogStoreAgent::AGENT_TYPE . '.error.log';
        if (is_file($agentErrorLog)) {
            unlink($agentErrorLog);
        }
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    /**
     * A node coming up after the aggregator would otherwise be missing from the cluster picture
     * for a whole interval, with nothing about the gap to say it is only the schedule.
     */
    public function testTheFirstFrameGoesOutOnStartWithoutWaitingForTheInterval(): void
    {
        $this->write('agent-a.log', 100);

        $this->startedAgent();

        $frame = $this->frame();
        $this->assertTrue($frame->available);
        $this->assertSame(['agent-a.log'], array_column($frame->toIndex()->keys, 'key'));
        $this->assertSame([], $this->queuedFrames(), 'The start owes exactly one frame');
    }

    public function testAChangeInsideTheIntervalIsNotWorthAFrame(): void
    {
        $agent = $this->startedAgent();
        $this->frame();

        $this->write('agent-a.log', 100);
        $agent->walkStore($this->stamp());
        $agent->pushIndexIfDue($this->at(self::INSIDE_THE_DEFAULT_INTERVAL_SECONDS));

        $this->assertSame([], $this->queuedFrames());
    }

    public function testAChangePastTheIntervalIsReported(): void
    {
        $agent = $this->startedAgent();
        $this->frame();

        $this->write('agent-a.log', 100);
        $agent->walkStore($this->stamp());
        $agent->pushIndexIfDue($this->at(self::PAST_THE_DEFAULT_INTERVAL_SECONDS));

        $this->assertSame(['agent-a.log'], array_column($this->frame()->toIndex()->keys, 'key'));
    }

    /**
     * The keepalive is not a sign of life - cluster membership answers that - but the way an
     * aggregator restarted or moved by policy gets a picture of a system where nothing is
     * happening, instead of waiting for the next thing to happen in its logs.
     */
    public function testASilentNodeStillReportsOnceTheKeepaliveHasPassed(): void
    {
        $agent = $this->startedAgent();
        $this->frame();

        $agent->pushIndexIfDue($this->at(self::PAST_THE_DEFAULT_INTERVAL_SECONDS));
        $this->assertSame([], $this->queuedFrames(), 'Nothing changed, so the interval alone owes nothing');

        $agent->pushIndexIfDue($this->at(self::PAST_THE_KEEPALIVE_SECONDS));

        $this->assertTrue($this->frame()->available);
    }

    /**
     * Walks are far more frequent than frames, so the walk that happens to be the latest when a
     * frame comes due has usually found nothing. Reading the change off that one would deny what
     * an earlier walk did find and lose it for good.
     */
    public function testAChangeIsNotLostToAQuietWalkThatFollowsIt(): void
    {
        $agent = $this->startedAgent();
        $this->frame();

        $this->write('agent-a.log', 100);
        $agent->walkStore($this->stamp());
        // A second walk finding nothing new, which is what the ordinary node does between changes.
        $agent->walkStore($this->stamp());
        $agent->pushIndexIfDue($this->at(self::PAST_THE_DEFAULT_INTERVAL_SECONDS));

        $this->assertSame(['agent-a.log'], array_column($this->frame()->toIndex()->keys, 'key'));
    }

    /**
     * A directory that cannot be read is a report of its own: the overview draws it as "no data",
     * where a node that said nothing at all would draw nothing at all. A node that comes up unable
     * to read its own store says so with its very first frame.
     */
    public function testANodeThatStartsUnableToReadItsStoreSaysSoAtOnce(): void
    {
        putenv(EnvConstants::DAEMON_LOG_FILE->name);

        $this->startedAgent();

        $frame = $this->frame();
        $this->assertFalse($frame->available);
        $this->assertSame([], $frame->keys);
    }

    /**
     * A walk that found nothing is what the sender asks about before spending a frame, and on a
     * quiet node that is most walks.
     */
    public function testADeltaWithNothingInItIsEmpty(): void
    {
        $this->assertTrue(new NodeLogIndexDelta([], [], [], [], [], [], [], [], false, false, false)->isEmpty());
    }

    /**
     * Crossing between readable and unreadable moves no key and no batch, so it would slip past a
     * question asked only about those - and a store that has gone out of reach is precisely the
     * thing the cluster picture must not go on showing as healthy.
     */
    public function testCrossingIntoUnavailabilityCountsAsAChangeOnItsOwn(): void
    {
        $this->assertFalse(new NodeLogIndexDelta([], [], [], [], [], [], [], [], true, false, false)->isEmpty());
    }

    /**
     * A takeout confirmation moves nothing else at all - the same batches, the same files, the same
     * weights - so without an axis of its own the frame carrying an operator's click is judged
     * empty and never sent (HIL-483).
     */
    public function testAConfirmedBatchCountsAsAChangeOnItsOwn(): void
    {
        $this->assertFalse(new NodeLogIndexDelta([], [], [], [], [], [1756166400], [], [], false, false, false)->isEmpty());
    }

    /**
     * And so does its withdrawal, for the same reason read backwards: an operator taking a
     * confirmation back moves nothing but the marker, so an axis of its own is what gets the frame
     * out of the node at all (HIL-759).
     */
    public function testAWithdrawnBatchCountsAsAChangeOnItsOwn(): void
    {
        $this->assertFalse(new NodeLogIndexDelta([], [], [], [], [], [], [1756166400], [], false, false, false)->isEmpty());
    }

    /**
     * And so does a retention verdict that moved, which is the one change here that needs no file
     * to move with it: the clock crossing the age threshold, or an administrator raising the
     * keep-count, leaves two walks identical in every weight and marker (HIL-871).
     */
    public function testAChangedVerdictCountsAsAChangeOnItsOwn(): void
    {
        $this->assertFalse(new NodeLogIndexDelta([], [], [], [], [], [], [], [1756166400], false, false, false)->isEmpty());
    }

    /**
     * And so does the tail of failures the overview panel draws: a stream that got a line back
     * after a rotation can weigh exactly what it weighed a walk ago, and without an axis of its
     * own the newest failure on a quiet node waits for the keepalive frame (HIL-867).
     */
    public function testAMovedErrorTailCountsAsAChangeOnItsOwn(): void
    {
        $this->assertFalse(new NodeLogIndexDelta([], [], [], [], [], [], [], [], false, true, false)->isEmpty());
    }

    /**
     * And the ring of warnings on its own (HIL-868): the newest warning on a quiet node would
     * otherwise wait for the keepalive frame a minute later.
     */
    public function testADeltaCarryingOnlyAMovedRingOfWarningsIsNotEmpty(): void
    {
        $this->assertFalse(new NodeLogIndexDelta([], [], [], [], [], [], [], [], false, false, true)->isEmpty());
    }

    public function testTheWrittenSettingSetsTheInterval(): void
    {
        LogSettingsResolverTestAccessor::$values[LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS] = '20000';
        $agent = $this->startedAgent();
        $this->frame();

        $this->write('agent-a.log', 100);
        $agent->walkStore($this->stamp());
        $agent->pushIndexIfDue($this->at(self::PAST_THE_DEFAULT_INTERVAL_SECONDS));
        $this->assertSame([], $this->queuedFrames(), 'The written 20 s outranks the built-in 5 s');

        $agent->pushIndexIfDue($this->at(20.5));

        $this->assertTrue($this->frame()->available);
    }

    /**
     * A written value below the floor is refused by LogIndexPushIntervalRule, the fallback answers,
     * the node follows the 5 s rhythm and the journal carries a complaint naming the setting key.
     */
    public function testAWrittenValueBelowTheFloorIsRefused(): void
    {
        LogSettingsResolverTestAccessor::$values[LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS] = '50';
        $agent = $this->startedAgent();
        $this->frame();

        $this->write('agent-a.log', 100);
        $agent->walkStore($this->stamp());
        $agent->pushIndexIfDue($this->at(self::INSIDE_THE_DEFAULT_INTERVAL_SECONDS));
        $this->assertSame([], $this->queuedFrames(), 'The refused 50 ms falls back to 5 s, not sent early');

        $agent->pushIndexIfDue($this->at(self::PAST_THE_DEFAULT_INTERVAL_SECONDS));

        $this->assertTrue($this->frame()->available);
        $journal = $this->agentErrorJournal($agent);
        $this->assertNotNull($journal);
        $this->assertStringContainsString(LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS, $journal);
    }

    /**
     * A non-numeric value is refused by the rule and falls back to the environment; the fallback
     * interval is obeyed and the complaint goes to the journal.
     */
    public function testANonNumericWrittenValueLeavesTheBuiltInIntervalInPlace(): void
    {
        LogSettingsResolverTestAccessor::$values[LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS] = 'as often as you like';
        $agent = $this->startedAgent();
        $this->frame();

        $this->write('agent-a.log', 100);
        $agent->walkStore($this->stamp());
        $agent->pushIndexIfDue($this->at(self::INSIDE_THE_DEFAULT_INTERVAL_SECONDS));
        $this->assertSame([], $this->queuedFrames());

        $agent->pushIndexIfDue($this->at(self::PAST_THE_DEFAULT_INTERVAL_SECONDS));

        $this->assertTrue($this->frame()->available);
        $journal = $this->agentErrorJournal($agent);
        $this->assertNotNull($journal);
        $this->assertStringContainsString(LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS, $journal);
    }

    /**
     * With no settings initialized in this process, the fallback interval is used.
     */
    public function testWithoutADatabaseTheBuiltInIntervalIsUsed(): void
    {
        Hilos::$setting = null;
        $agent = $this->startedAgent();
        $this->frame();

        $this->write('agent-a.log', 100);
        $agent->walkStore($this->stamp());
        $agent->pushIndexIfDue($this->at(self::INSIDE_THE_DEFAULT_INTERVAL_SECONDS));
        $this->assertSame([], $this->queuedFrames());

        $agent->pushIndexIfDue($this->at(self::PAST_THE_DEFAULT_INTERVAL_SECONDS));

        $this->assertTrue($this->frame()->available);
    }

    /**
     * With no settings initialized in the process and the interval configured via environment,
     * the node obeys the environment value.
     */
    public function testWithoutSettingsTheEnvironmentIntervalIsObeyed(): void
    {
        Hilos::$setting = null;
        putenv(EnvConstants::LOG_INDEX_PUSH_INTERVAL_MS->name . '=20000');
        $agent = $this->startedAgent();
        $this->frame();

        $this->write('agent-a.log', 100);
        $agent->walkStore($this->stamp());
        $agent->pushIndexIfDue($this->at(self::PAST_THE_DEFAULT_INTERVAL_SECONDS));
        $this->assertSame([], $this->queuedFrames(), 'The environment 20 s outranks the fallback 5 s');

        $agent->pushIndexIfDue($this->at(20.5));

        $this->assertTrue($this->frame()->available);
    }

    /**
     * Agent started over the fixture directory, its first frame already queued.
     *
     * @return LogStoreAgent Started agent
     */
    private function startedAgent(): LogStoreAgent
    {
        $agent = new LogStoreAgent();
        $agent->onStart();
        $this->startedAt = microtime(true);

        return $agent;
    }

    /**
     * A clock reading the given number of seconds after the agent's start frame.
     *
     * Measured from the instant the start was taken and NOT from a fresh reading: the agent stamps
     * its last frame with the real clock, so a reading taken at the assertion would carry whatever
     * the case did in between into the elapsed time. The cases stand half a second either side of
     * the interval they probe, and a directory walk on a loaded box is well able to eat a share of that.
     *
     * @param float $seconds Seconds after the start frame
     * @return float Wall clock that many seconds past it
     */
    private function at(float $seconds): float
    {
        return $this->startedAt + $seconds;
    }

    /**
     * @return int Timestamp to stamp a walk with, which no case here reads back
     */
    private function stamp(): int
    {
        return time();
    }

    /**
     * Takes the one frame the queue is expected to hold.
     *
     * @return NodeLogIndexSignalData Payload of that frame
     */
    private function frame(): NodeLogIndexSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'A frame was due and nothing was sent');
        $this->assertSame(HilosSignalConstants::LOGS_NODE_INDEX_REPORT, $signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(NodeLogIndexSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Drains the queue, so a case can say that nothing at all was sent.
     *
     * @return list<string> Name of every queued signal, in the order they were sent
     */
    private function queuedFrames(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }

    /**
     * Writes one live log file of the given size, replacing whatever was there.
     *
     * @param string $name Basename to write
     * @param int $bytes Size in bytes
     */
    private function write(string $name, int $bytes): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, str_repeat('x', $bytes));
    }

    /**
     * Recursively removes a directory tree.
     *
     * @param string $path Directory or file to remove
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
    }

    /**
     * Reads the agent error stream file written under the master log directory.
     *
     * @param LogStoreAgent $agent Agent under test
     * @return ?string Contents of the agent error stream, or null if not written
     */
    private function agentErrorJournal(LogStoreAgent $agent): ?string
    {
        $file = AgentLogStream::pathFor(dirname($this->logFile), $agent->getId(), true);

        return is_file($file) ? (string)file_get_contents($file) : null;
    }
}
