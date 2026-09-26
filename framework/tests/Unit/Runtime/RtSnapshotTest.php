<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Hilos;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/**
 * The hand-over of a standalone RT item between nodes (HIL-586), and the rows of a set a node
 * hands over (HIL-1116).
 *
 * A truth source may be registered for one item rather than a collection — the backup runtime
 * is one — and the per-row path already carries those between processes. A snapshot that only
 * knew about collections would leave exactly that shape of state never handed to a node that
 * joins: no rows to hand over, nothing to apply, and no line anywhere saying so.
 *
 * A node owning a set hands over the rows of its set, cut by the field the row class names. A
 * row keyed by a number and a set named by a string are one set, the way the write door reads
 * them - otherwise the rows of the set would never be handed over at all.
 */
final class RtSnapshotTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$rt = null;

        parent::tearDown();
    }

    /**
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    public function testAStandaloneItemIsReadAsTheOneRowItIs(): void
    {
        $context = $this->arrangeContext('Ada');

        $this->assertSame(
            [RtSnapshotTestState::ID => ['id' => RtSnapshotTestState::ID, 'name' => 'Ada']],
            RtSnapshot::rows(RtSnapshotTestRtContext::ITEM),
        );
        $this->assertSame('Ada', $context->item->name);
    }

    /**
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    public function testAStandaloneItemTakesTheRowTheOwnerHandedOver(): void
    {
        $context = $this->arrangeContext('Ada');

        RtSnapshot::replace(
            RtSnapshotTestRtContext::ITEM,
            [RtSnapshotTestState::ID => ['id' => RtSnapshotTestState::ID, 'name' => 'Grace']],
        );

        $this->assertSame('Grace', $context->item->name);
    }

    /**
     * The item is mounted by the context on both nodes rather than created by a write, so an
     * empty snapshot means "nothing to say about it" and not "clear it".
     *
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    public function testAnEmptySnapshotLeavesAStandaloneItemAsItWas(): void
    {
        $context = $this->arrangeContext('Ada');

        RtSnapshot::replace(RtSnapshotTestRtContext::ITEM, []);

        $this->assertSame('Ada', $context->item->name);
    }

    /**
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    public function testSetRowsAreTheRowsWhoseFieldNamesTheSet(): void
    {
        $this->arrangeContext('Ada');

        $this->assertSame(['a', 'c'], array_keys(RtSnapshot::setRows(RtSnapshotTestRtContext::SETS, ['42'])));
        $this->assertSame(['a', 'b', 'c'], array_keys(RtSnapshot::setRows(RtSnapshotTestRtContext::SETS, ['42', '7'])));
        $this->assertSame(
            ['id' => 'b', 'ownerId' => '7'],
            RtSnapshot::setRows(RtSnapshotTestRtContext::SETS, ['7'])['b'],
            'The rows come as the collection holds them',
        );
        $this->assertSame([], RtSnapshot::setRows(RtSnapshotTestRtContext::SETS, []), 'No set asked, no row');
    }

    /**
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    public function testACollectionCutByNoFieldHasNoSetRows(): void
    {
        $this->arrangeContext('Ada');

        $this->assertSame([], RtSnapshot::setRows(RtSnapshotTestRtContext::PLAIN, ['42']));
        $this->assertNull(RtSnapshot::setKeyOfRow(RtSnapshotTestRtContext::PLAIN, ['id' => 'p', 'ownerId' => '42']));
    }

    /**
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    public function testTheSetKeyOfARowIsItsFieldAsAString(): void
    {
        $this->arrangeContext('Ada');

        $this->assertSame('42', RtSnapshot::setKeyOfRow(RtSnapshotTestRtContext::SETS, ['id' => 'a', 'ownerId' => 42]));
        $this->assertSame('7', RtSnapshot::setKeyOfRow(RtSnapshotTestRtContext::SETS, ['id' => 'b', 'ownerId' => '7']));
        $this->assertNull(RtSnapshot::setKeyOfRow(RtSnapshotTestRtContext::SETS, ['id' => 'x', 'ownerId' => null]));
        $this->assertNull(RtSnapshot::setKeyOfRow(RtSnapshotTestRtContext::SETS, ['id' => 'x']));
        $this->assertNull(RtSnapshot::setKeyOfRow(RtSnapshotTestRtContext::SETS, ['id' => 'x', 'ownerId' => ['42']]));
    }

    /**
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    public function testASetKeyIsAskedOfNoMountedCollectionAsNull(): void
    {
        $this->arrangeContext('Ada');

        $this->assertNull(RtSnapshot::setKeyOfRow('rtSnapshotTestNothing', ['id' => 'a', 'ownerId' => '42']));
        $this->assertSame([], RtSnapshot::setRows('rtSnapshotTestNothing', ['42']));
        $this->assertNull(
            RtSnapshot::setKeyOfRow(RtSnapshotTestRtContext::ITEM, ['id' => 'a', 'ownerId' => '42']),
            'A standalone item is no collection to cut',
        );
    }

    /**
     * Mounts the context these cases hand over.
     *
     * @param string $name Label the mounted row starts with
     * @return RtSnapshotTestRtContext Mounted context, holding the item directly
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    private function arrangeContext(string $name): RtSnapshotTestRtContext
    {
        $context = new RtSnapshotTestRtContext($name);
        $context->configure();
        Hilos::$rt = $context;

        return $context;
    }
}

/**
 * Runtime context mounting one standalone item and keeping it reachable for the assertions,
 * beside two collections: one cut into sets by a field of its rows, and one cut by none.
 */
final class RtSnapshotTestRtContext extends RtContext
{
    public const string ITEM = 'rtSnapshotTestItem';

    public const string SETS = 'rtSnapshotTestSets';

    public const string PLAIN = 'rtSnapshotTestPlain';

    /** The mounted row, kept so a case can read it back without a lookup by key */
    public readonly RtSnapshotTestState $item;

    /**
     * @param string $name Label the mounted row starts with
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     */
    public function __construct(string $name)
    {
        $this->item = RtSnapshotTestState::fromRow(['id' => RtSnapshotTestState::ID, 'name' => $name]);
    }

    /**
     * Registers the single standalone item and the two collections.
     *
     * @throws InvalidFormatException When a fixture row is not one the state can be built from
     */
    public function configure(): void
    {
        $this->_stateItems[self::ITEM] = $this->item;

        $sets = RtSnapshotTestSetStates::init();
        foreach ([['a', '42'], ['b', '7'], ['c', '42']] as [$id, $ownerId]) {
            $sets->add(RtSnapshotTestSetState::fromRow(['id' => $id, 'ownerId' => $ownerId]));
        }
        $this->_stateCollections[self::SETS] = $sets;

        $plain = RtSnapshotTestStates::init();
        $plain->add(RtSnapshotTestState::fromRow(['id' => 'p', 'name' => 'Ada']));
        $this->_stateCollections[self::PLAIN] = $plain;
    }
}

final class RtSnapshotTestStates extends RtStates
{
    public const string STATE_CLASS = RtSnapshotTestState::class;
}

final class RtSnapshotTestSetStates extends RtStates
{
    public const string STATE_CLASS = RtSnapshotTestSetState::class;
}

/**
 * A row cut into sets by its owner: the set it is in is the value of that field.
 */
final class RtSnapshotTestSetState extends RtState
{
    public const string id = 'id';

    public const string ownerId = 'ownerId';

    public const string SET_VIA = self::ownerId;

    private(set) string $id = '';

    private(set) string $ownerId = '';

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated row
     * @throws InvalidFormatException When the row is missing a field it is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = self::requireString($row, self::id);
        $instance->ownerId = self::requireString($row, self::ownerId);
        $instance->markRtSyncBaseline();

        return $instance;
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
        return [self::id => $this->id, self::ownerId => $this->ownerId];
    }
}

/**
 * The standalone row: an id fixed by the context and a label the hand-over carries.
 */
final class RtSnapshotTestState extends RtState
{
    public const string ID = 'the-one';

    private(set) string $id = '';

    public string $name = '';

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated row
     * @throws InvalidFormatException When the row is missing a field it is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = self::requireRowString($row, 'id');
        $instance->name = self::requireRowString($row, 'name');
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $diff Fields the hand-over or a delta carries
     * @throws InvalidFormatException When a field it carries holds another type
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists('name', $diff)) {
            $this->name = self::requireRowString($diff, 'name');
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
        return ['id' => $this->id, 'name' => $this->name];
    }

    /**
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return string Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-string
     */
    private static function requireRowString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidFormatException('Runtime row carries no string under key ' . $key);
        }

        return $value;
    }
}
