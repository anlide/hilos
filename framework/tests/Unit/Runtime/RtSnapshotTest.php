<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Hilos;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/**
 * The hand-over of a standalone RT item between nodes (HIL-586).
 *
 * A truth source may be registered for one item rather than a collection — the backup runtime
 * is one — and the per-row path already carries those between processes. A snapshot that only
 * knew about collections would leave exactly that shape of state never handed to a node that
 * joins: no rows to hand over, nothing to apply, and no line anywhere saying so.
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
 * Runtime context mounting one standalone item and keeping it reachable for the assertions.
 */
final class RtSnapshotTestRtContext extends RtContext
{
    public const string ITEM = 'rtSnapshotTestItem';

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
     * Registers the single standalone item.
     */
    public function configure(): void
    {
        $this->_stateItems[self::ITEM] = $this->item;
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
