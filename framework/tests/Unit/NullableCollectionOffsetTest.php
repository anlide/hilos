<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Object\Collection\ObjectCollection;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions as RtCollectionActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for null offset handling in framework collection contracts.
 */
final class NullableCollectionOffsetTest extends TestCase
{
    public function testDatabaseCollectionsTreatNullOffsetAsMissing(): void
    {
        $dbCollection = NullableOffsetDbCollection::init();
        $objects = NullableOffsetObjects::initEmpty();
        $objectCollection = ObjectCollection::empty();
        $entityCollection = EntityCollection::empty();

        $this->assertFalse(isset($dbCollection[null]));
        $this->assertNull($dbCollection[null]);
        unset($dbCollection[null]);

        $this->assertFalse(isset($objects[null]));
        $this->assertNull($objects[null]);
        unset($objects[null]);

        $this->assertFalse(isset($objectCollection[null]));
        $this->assertNull($objectCollection[null]);
        unset($objectCollection[null]);

        $this->assertFalse(isset($entityCollection[null]));
        $this->assertNull($entityCollection[null]);
        unset($entityCollection[null]);
    }

    public function testRuntimeCollectionsDoNotTreatNullAsEmptyStringKey(): void
    {
        $state = NullableOffsetRtState::create('');
        $states = NullableOffsetRtStates::init();
        $states->add($state);

        $collection = NullableOffsetRtCollection::init();
        $collection->setStateCollection($states);

        $this->assertFalse($states->has(null));
        $this->assertNull($states->get(null));
        $this->assertFalse(isset($states[null]));
        $this->assertNull($states[null]);
        $this->assertSame($state, $states['']);

        $this->assertFalse(isset($collection[null]));
        $this->assertNull($collection[null]);
        $this->assertSame('', $collection['']?->getId());
    }
}

/**
 * Minimal DB collection used for null-offset contract tests.
 */
final class NullableOffsetDbCollection extends DbCollection
{
}

/**
 * Minimal object collection used for null-offset contract tests.
 */
final class NullableOffsetObjects extends Objects
{
}

/**
 * Minimal runtime state collection used for null-offset contract tests.
 *
 * @extends RtStates<NullableOffsetRtState>
 */
final class NullableOffsetRtStates extends RtStates
{
    public const string STATE_CLASS = NullableOffsetRtState::class;
}

/**
 * Minimal runtime state item used for null-offset contract tests.
 */
final class NullableOffsetRtState extends RtState
{
    private string $id = '';

    public static function create(string $id): self
    {
        $state = new self();
        $state->id = $id;

        return $state;
    }

    public static function fromRow(array $row): static
    {
        return self::create((string)($row['id'] ?? ''));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function toArray(): array
    {
        return ['id' => $this->id];
    }
}

/**
 * Minimal runtime view collection used for null-offset contract tests.
 *
 * @extends RtCollection<NullableOffsetRtItem, RtCollectionActions>
 */
final class NullableOffsetRtCollection extends RtCollection
{
    protected function createRtItem(RtState &$state): RtItem
    {
        return new NullableOffsetRtItem($state);
    }
}

/**
 * Minimal runtime view item used for null-offset contract tests.
 *
 * @extends RtItem<NullableOffsetRtState>
 */
final class NullableOffsetRtItem extends RtItem
{
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
