<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\View\Item\DbItem;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Item\RtItem;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what a view wrapper holds: the row it was built from, and not the
 * variable that row was handed over in.
 *
 * Both wrappers used to bind their backing row by reference - `$this->_object =
 * &$object` and `$this->_state = &$state` - and `foreach` reuses one variable for the
 * whole walk. So three wrappers built inside one walk all pointed at the same variable
 * and all showed whatever row came last; the framework carried an `unset($object)`
 * patch in `DbCollection::toArray()` for exactly that reason, and two more places were
 * saved only by not outliving the next iteration. Nothing crashed - the wrong row was
 * simply served.
 *
 * The guard rule judges how the binding is written. This judges what it does, which is
 * the part a rewrite could keep passing while quietly reintroducing the trap.
 */
final class ViewWrapperBindingTest extends TestCase
{
    /**
     * @var array<int, int> Primary keys walked, distinct on purpose: equal ones would pass either way
     */
    private const array DB_KEYS = [11, 22, 33];

    /**
     * @var array<int, string> State ids walked, distinct for the same reason
     */
    private const array RT_IDS = ['first', 'second', 'third'];

    public function testDbItemsFromOneWalkKeepTheirOwnRow(): void
    {
        $rows = [];
        foreach (self::DB_KEYS as $key) {
            $rows[] = WrapperBindObject::fromEntity(WrapperBindEntity::withId($key));
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = new WrapperBindDbItem($row);
        }

        $this->assertSame(
            array_map(static fn(int $key): string => (string)$key, self::DB_KEYS),
            array_map(static fn(WrapperBindDbItem $item): string => $item->getIdString(), $items),
            'DbItems built inside one walk followed the walk variable instead of keeping their own row',
        );
    }

    public function testRtItemsFromOneWalkKeepTheirOwnRow(): void
    {
        $rows = [];
        foreach (self::RT_IDS as $id) {
            $rows[] = WrapperBindState::create($id);
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = new WrapperBindRtItem($row);
        }

        $this->assertSame(
            self::RT_IDS,
            array_map(static fn(WrapperBindRtItem $item): string => $item->getId(), $items),
            'RtItems built inside one walk followed the walk variable instead of keeping their own row',
        );
    }
}

/**
 * Minimal single-column entity for the wrapper-binding fixtures.
 */
final class WrapperBindEntity extends Entity
{
    public const string _table = 'view_wrapper_binding_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;

    /**
     * @param int $id Primary key
     * @return self Built entity
     */
    public static function withId(int $id): self
    {
        $entity = new self();
        $entity->id = $id;

        return $entity;
    }
}

/**
 * Minimal object fixture wrapping the wrapper-binding entity.
 */
final class WrapperBindObject extends Object_
{
    public const string ENTITY_CLASS = WrapperBindEntity::class;
}

/**
 * Minimal DB view item: the base wrapper is what is under test, so it adds nothing.
 */
final class WrapperBindDbItem extends DbItem
{
}

/**
 * Minimal runtime state item for the wrapper-binding fixtures.
 */
final class WrapperBindState extends RtState
{
    private string $id = '';

    /**
     * @param string $id State ID
     * @return self Built state
     */
    public static function create(string $id): self
    {
        $state = new self();
        $state->id = $id;

        return $state;
    }

    public static function fromRow(array $row): static
    {
        return self::create((string)$row['id']);
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
 * Minimal runtime view item: the base wrapper is what is under test.
 *
 * @extends RtItem<WrapperBindState>
 */
final class WrapperBindRtItem extends RtItem
{
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
