<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Database\View\Item\Setting as ViewSetting;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\NodeLogIndexDelta;
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

    private ?DbContext $previousDb = null;

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
        $this->previousDb = Hilos::$db;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dir . '/daemon.log');
        Hilos::$sr = new SignalRouter();
        // No settings row by default: that is the ordinary installation, where every node of the
        // cluster runs at the one interval written into the framework rather than into an env.
        LogIndexPushSettingsCollection::$writtenValue = null;
        Hilos::$db = LogIndexPushDbContext::create();
    }

    protected function tearDown(): void
    {
        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        Hilos::$db = $this->previousDb;
        Hilos::$sr = null;
        LogIndexPushSettingsCollection::$writtenValue = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
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
        $this->assertTrue(new NodeLogIndexDelta([], [], [], [], [], [], [], [], false)->isEmpty());
    }

    /**
     * Crossing between readable and unreadable moves no key and no batch, so it would slip past a
     * question asked only about those - and a store that has gone out of reach is precisely the
     * thing the cluster picture must not go on showing as healthy.
     */
    public function testCrossingIntoUnavailabilityCountsAsAChangeOnItsOwn(): void
    {
        $this->assertFalse(new NodeLogIndexDelta([], [], [], [], [], [], [], [], true)->isEmpty());
    }

    /**
     * A takeout confirmation moves nothing else at all - the same batches, the same files, the same
     * weights - so without an axis of its own the frame carrying an operator's click is judged
     * empty and never sent (HIL-483).
     */
    public function testAConfirmedBatchCountsAsAChangeOnItsOwn(): void
    {
        $this->assertFalse(new NodeLogIndexDelta([], [], [], [], [], [1756166400], [], [], false)->isEmpty());
    }

    /**
     * And so does its withdrawal, for the same reason read backwards: an operator taking a
     * confirmation back moves nothing but the marker, so an axis of its own is what gets the frame
     * out of the node at all (HIL-759).
     */
    public function testAWithdrawnBatchCountsAsAChangeOnItsOwn(): void
    {
        $this->assertFalse(new NodeLogIndexDelta([], [], [], [], [], [], [1756166400], [], false)->isEmpty());
    }

    /**
     * And so does a retention verdict that moved, which is the one change here that needs no file
     * to move with it: the clock crossing the age threshold, or an administrator raising the
     * keep-count, leaves two walks identical in every weight and marker (HIL-871).
     */
    public function testAChangedVerdictCountsAsAChangeOnItsOwn(): void
    {
        $this->assertFalse(new NodeLogIndexDelta([], [], [], [], [], [], [], [1756166400], false)->isEmpty());
    }

    public function testTheWrittenSettingSetsTheInterval(): void
    {
        LogIndexPushSettingsCollection::$writtenValue = '20000';
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
     * A row can be older than the rule that guards the setting, or written past it, so the floor
     * is applied again where the value is obeyed: a broken row in the database is treated rather
     * than carried out.
     */
    public function testAWrittenValueBelowTheFloorIsClampedToIt(): void
    {
        LogIndexPushSettingsCollection::$writtenValue = '50';
        $agent = $this->startedAgent();
        $this->frame();

        $this->write('agent-a.log', 100);
        $agent->walkStore($this->stamp());
        $agent->pushIndexIfDue($this->at(0.05));
        $this->assertSame([], $this->queuedFrames(), 'The written 50 ms is not obeyed below the 100 ms floor');

        $agent->pushIndexIfDue($this->at(0.15));

        $this->assertTrue($this->frame()->available);
    }

    public function testANonNumericWrittenValueLeavesTheBuiltInIntervalInPlace(): void
    {
        LogIndexPushSettingsCollection::$writtenValue = 'as often as you like';
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
     * With no row written the interval is a literal in the framework and not the node's own
     * environment, so three nodes of one cluster report at one rate out of the box.
     */
    public function testWithoutADatabaseTheBuiltInIntervalIsUsed(): void
    {
        Hilos::$db = null;
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
     * the case did in between into the elapsed time. The floor case allows fifty milliseconds, and
     * a directory walk on a loaded box is well able to eat that.
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
}

/**
 * Settings view answering one written row and no database behind it.
 *
 * The agent reads the WRITTEN value of the push interval, which is a lookup by key on this
 * collection; the real one asks the database for it, so the cases substitute this and set the row
 * they are about.
 */
final class LogIndexPushSettingsCollection extends DbCollection
{
    /** @var ?string Value the one row holds, or null when no row has been written */
    public static ?string $writtenValue = null;

    public const string DB_ITEM_CLASS = ViewSetting::class;

    /**
     * @param mixed $offset Setting key
     * @return ?DbItem Row holding {@see self::$writtenValue}, or null when nothing was written
     */
    public function offsetGet(mixed $offset): ?DbItem
    {
        if (self::$writtenValue === null || !is_string($offset)) {
            return null;
        }

        $entity = new EntitySetting();
        $entity->id = 1;
        $entity->key = $offset;
        $entity->value = self::$writtenValue;

        return new ViewSetting(ObjectSetting::fromEntity($entity));
    }

    /**
     * @param Object_ $object Stored row
     * @return DbItem View of that row
     */
    protected function createDbItem(Object_ $object): DbItem
    {
        return new ViewSetting($object);
    }
}

/**
 * DB context mounting the settings collection alone, which is all the sender reads.
 */
final class LogIndexPushDbContext extends HilosDbContext
{
    /**
     * Mounts the settings view by key, so a read needs no database behind it.
     *
     * @return self Mounted context
     */
    public static function create(): self
    {
        $context = new self();
        $context->_objectCollections[self::settings] = ObjectSettings::initDB(Objects::LAZY_STRATEGY_KEY);
        $context->setRepresent(self::settings, LogIndexPushSettingsCollection::class);

        return $context;
    }

    public function configure(): void
    {
    }
}
