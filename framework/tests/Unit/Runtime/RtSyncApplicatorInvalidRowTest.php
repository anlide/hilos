<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/**
 * What an RT row a state refuses costs the worker that receives it (HIL-565).
 *
 * A runtime row arrives from another process, and the state now refuses one it
 * cannot be built from rather than hydrating a row of zeros. That refusal has
 * nowhere to go: the worker loop around `handleDaemonMessage()` catches only its
 * two agent exceptions, so an escaping `InvalidFormatException` would take the
 * worker down — and with it every subscriber it serves — over one broken row of
 * one collection.
 *
 * So the applicator traps it around the single row. What is asserted is that
 * blast radius, and its limit: a refused create never enters the collection at
 * all, a refused diff stops at the field that broke it and keeps whatever it
 * had already written, both are named in the log, and a well-formed row that
 * follows still arrives.
 */
final class RtSyncApplicatorInvalidRowTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testARowMissingARequiredFieldIsNotApplied(): void
    {
        $collection = $this->arrangeCollection();

        ob_start();
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            InvalidRowTestRtContext::ROWS,
            '7',
            [InvalidRowTestState::id => '7'],
        ));
        $logged = (string)ob_get_clean();

        $this->assertFalse($collection->has('7'));
        $this->assertStringContainsString(InvalidRowTestRtContext::ROWS, $logged);
        $this->assertStringContainsString('7', $logged);
        $this->assertStringContainsString(InvalidRowTestState::name, $logged);
    }

    public function testAWellFormedRowIsStillApplied(): void
    {
        $collection = $this->arrangeCollection();

        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            InvalidRowTestRtContext::ROWS,
            '7',
            [
                InvalidRowTestState::id => '7',
                InvalidRowTestState::name => 'Ada',
                InvalidRowTestState::seenAt => 1710000000,
            ],
        ));

        $state = $collection->get('7');

        $this->assertInstanceOf(InvalidRowTestState::class, $state);
        $this->assertSame('Ada', $state->name);
    }

    public function testARowRefusedByOneCollectionDoesNotStopTheNextOne(): void
    {
        $collection = $this->arrangeCollection();

        ob_start();
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            InvalidRowTestRtContext::ROWS,
            '7',
            [InvalidRowTestState::id => '7'],
        ));
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            InvalidRowTestRtContext::ROWS,
            '8',
            [
                InvalidRowTestState::id => '8',
                InvalidRowTestState::name => 'Grace',
                InvalidRowTestState::seenAt => 1710000000,
            ],
        ));
        ob_end_clean();

        $this->assertFalse($collection->has('7'));
        $this->assertTrue($collection->has('8'));
    }

    public function testADiffRefusedAtItsOnlyFieldLeavesTheRowAsItWas(): void
    {
        $collection = $this->arrangeCollection();
        $this->arrangeRow('7', 'Ada');

        ob_start();
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            InvalidRowTestRtContext::ROWS,
            '7',
            [InvalidRowTestState::name => ['not', 'a', 'name']],
        ));
        $logged = (string)ob_get_clean();

        $state = $collection->get('7');

        $this->assertInstanceOf(InvalidRowTestState::class, $state);
        $this->assertSame('Ada', $state->name);
        $this->assertStringContainsString(InvalidRowTestRtContext::ROWS, $logged);
        $this->assertStringContainsString(InvalidRowTestState::name, $logged);
    }

    public function testADiffRefusedPartWayKeepsWhatItHadAlreadyWritten(): void
    {
        // Pinned deliberately, because it is the limit of what the trap buys:
        // applyDiff() writes field by field and the trap is not a rollback, so a
        // diff refused at its second field leaves the first one applied. The row
        // is still not recorded as having accepted it — the baseline stays where
        // it was, so the next local sync() re-sends the field rather than letting
        // a half-applied diff pass for agreed state.
        $collection = $this->arrangeCollection();
        $this->arrangeRow('7', 'Ada');

        ob_start();
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            InvalidRowTestRtContext::ROWS,
            '7',
            [
                InvalidRowTestState::name => 'Grace',
                InvalidRowTestState::seenAt => 'soon',
            ],
        ));
        $logged = (string)ob_get_clean();

        $state = $collection->get('7');

        $this->assertInstanceOf(InvalidRowTestState::class, $state);
        $this->assertSame('Grace', $state->name);
        $this->assertSame(0, $state->seenAt);
        $this->assertStringContainsString(InvalidRowTestState::seenAt, $logged);
    }

    public function testADiffThatOmitsAFieldStillMeansTheFieldDidNotChange(): void
    {
        $collection = $this->arrangeCollection();
        $this->arrangeRow('7', 'Ada');

        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            InvalidRowTestRtContext::ROWS,
            '7',
            [InvalidRowTestState::seenAt => 1710000000],
        ));

        $state = $collection->get('7');

        $this->assertInstanceOf(InvalidRowTestState::class, $state);
        $this->assertSame('Ada', $state->name);
        $this->assertSame(1710000000, $state->seenAt);
    }

    /**
     * Mounts the one runtime collection every case here syncs into.
     *
     * @return RtStates Empty collection the applicator writes to
     */
    private function arrangeCollection(): RtStates
    {
        Hilos::$rt = new InvalidRowTestRtContext();
        Hilos::$rt->configure();

        $collection = Hilos::$rt->getStateCollection(InvalidRowTestRtContext::ROWS);
        $this->assertInstanceOf(RtStates::class, $collection);

        return $collection;
    }

    /**
     * Seeds the row a diff case starts from, along the road such a row really arrives
     * by: a well-formed create, which {@see self::testAWellFormedRowIsStillApplied()}
     * pins as arriving whole. Putting it into the store by hand would arrange a row no
     * process ever produces — one whose membership was never announced.
     *
     * @param string $id Row key to seed
     * @param string $name Name the seeded row carries
     */
    private function arrangeRow(string $id, string $name): void
    {
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            InvalidRowTestRtContext::ROWS,
            $id,
            [
                InvalidRowTestState::id => $id,
                InvalidRowTestState::name => $name,
                InvalidRowTestState::seenAt => 0,
            ],
        ));
    }
}

/**
 * Runtime context holding the one collection the applicator syncs into.
 */
final class InvalidRowTestRtContext extends RtContext
{
    public const string ROWS = 'invalidRowTestRows';

    /**
     * Registers the single test collection.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = InvalidRowTestStates::init();
    }
}

final class InvalidRowTestStates extends RtStates
{
    public const string STATE_CLASS = InvalidRowTestState::class;
}

/**
 * Row that refuses a payload it cannot be built from, the way the chat runtime
 * rows of this ticket now do.
 */
final class InvalidRowTestState extends RtState
{
    public const string id = 'id';
    public const string name = 'name';
    public const string seenAt = 'seenAt';

    private(set) string $id = '';

    public string $name = '';

    public int $seenAt = 0;

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated row
     * @throws InvalidFormatException When the row is missing a field it is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = self::requireString($row, self::id);
        $instance->name = self::requireString($row, self::name);
        $instance->seenAt = self::requireInt($row, self::seenAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::name, $diff)) {
            $this->name = self::requireString($diff, self::name);
        }
        if (array_key_exists(self::seenAt, $diff)) {
            $this->seenAt = self::requireInt($diff, self::seenAt);
        }
    }

    /**
     * @return string Row key
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
            self::seenAt => $this->seenAt,
        ];
    }
}
