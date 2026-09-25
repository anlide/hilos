<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\Core\Exception\LogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\SetTree;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the climb from a row's set column to the key at the top of its set tree (HIL-1111).
 *
 * The tree is made up rather than taken from the framework, in the shape the live one has: a top
 * whose rows belong to nobody's set, a middle floor hung on it, a bottom floor hung on the middle -
 * the form of "person <- identity anchor <- passkey credential" - and a leaf one floor further
 * down. The rows live in memory: the context mounts empty collections and the cases seed them, so
 * a parent that is missing is missing and nothing reaches for a database.
 */
final class SetTreeTest extends TestCase
{
    private const string AGENT = 'unit_set_tree_agent';
    private const string OWN_TOP = '5';
    private const string FOREIGN_TOP = '6';

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$db = new SetTreeDbContext([
            SetTreeTops::class,
            SetTreeMiddles::class,
            SetTreeBottoms::class,
            SetTreeLeaves::class,
            SetTreeShortcuts::class,
        ]);
        Hilos::$db->configure();

        $this->seedMiddle(17, (int)self::OWN_TOP);
        $this->seedMiddle(18, (int)self::FOREIGN_TOP);
        $this->seedBottom(30, 17);
        $this->seedBottom(31, 18);
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::AGENT);
        Hilos::$db = null;

        parent::tearDown();
    }

    public function testAFloorHungOnATableInNobodysSetHasItsOwnValueAsTheTop(): void
    {
        $this->assertSame(self::OWN_TOP, SetTree::topOfSetKey(SetTreeMiddleEntity::class, self::OWN_TOP));
        $this->assertSame([], SetTree::walkOf(SetTreeMiddleEntity::class));
    }

    /**
     * The acceptance case of the leaf: a grandchild belongs to the owner of the top.
     */
    public function testAGrandchildClimbsThroughItsParentToTheTop(): void
    {
        $this->assertSame(self::OWN_TOP, SetTree::topOfSetKey(SetTreeBottomEntity::class, '17'));
        $this->assertSame(self::FOREIGN_TOP, SetTree::topOfSetKey(SetTreeBottomEntity::class, '18'));
        $this->assertSame(['set_tree_middle' => 'set_tree_middles'], SetTree::walkOf(SetTreeBottomEntity::class));

        $credential = SetTreeBottomObject::fromEntity(SetTreeBottomEntity::stored(40, 17));
        $this->assertSame([self::OWN_TOP], $credential->touchedSetKeys());
    }

    public function testARowThreeFloorsDownReachesTheSameTop(): void
    {
        $this->assertSame(self::OWN_TOP, SetTree::topOfSetKey(SetTreeLeafEntity::class, '30'));
        $this->assertSame(
            ['set_tree_bottom' => 'set_tree_bottoms', 'set_tree_middle' => 'set_tree_middles'],
            SetTree::walkOf(SetTreeLeafEntity::class),
        );
    }

    public function testASoftReferenceIsTheTopAndReadsNothing(): void
    {
        Hilos::$db = null;

        $this->assertSame('9', SetTree::topOfSetKey(SetTreeSoftEntity::class, '9'));
        $this->assertSame([], SetTree::walkOf(SetTreeSoftEntity::class));
    }

    public function testATableInNobodysSetHasNoTop(): void
    {
        $this->assertNull(SetTree::topOfSetKey(SetTreeTopEntity::class, '1'));
        $this->assertSame([], SetTree::walkOf(SetTreeTopEntity::class));
    }

    public function testARowWhoseParentIsGoneBelongsToNobodysSet(): void
    {
        $this->assertNull(SetTree::topOfSetKey(SetTreeBottomEntity::class, '99'));
        $this->assertSame([], SetTreeBottomObject::fromEntity(SetTreeBottomEntity::stored(41, 99))->touchedSetKeys());
    }

    public function testAParentTableThatIsNotMountedEndsTheClimbWithNoTop(): void
    {
        $this->assertNull(SetTree::topOfSetKey(SetTreeStrayEntity::class, '17'));
        $this->assertSame(['set_tree_elsewhere' => null], SetTree::walkOf(SetTreeStrayEntity::class));
    }

    /**
     * The acceptance case of the short path: the top comes off the row, and the parent is not read.
     *
     * The middle row the set column names does not exist, so a climb would answer nobody's set.
     */
    public function testAShortPathCarriesTheTopWithoutReadingTheParent(): void
    {
        $object = SetTreeShortcutObject::fromEntity(SetTreeShortcutEntity::stored(1, 99, (int)self::OWN_TOP));

        $this->assertSame([self::OWN_TOP], $object->touchedSetKeys());
        $this->assertSame(self::OWN_TOP, $object->storedSetTop());

        $object->moveTop((int)self::FOREIGN_TOP);
        $this->assertSame([self::OWN_TOP, self::FOREIGN_TOP], $object->touchedSetKeys());
    }

    /**
     * A parent answers by what the table holds, not by an edit of it this process has not saved.
     */
    public function testAParentIsClimbedByItsStoredPointer(): void
    {
        /** @var SetTreeMiddleObject $middle */
        $middle = Hilos::$db->getObjectCollection(SetTreeMiddles::COLLECTION_KEY)['17'];
        $middle->moveTop((int)self::FOREIGN_TOP);

        $this->assertSame(self::OWN_TOP, SetTree::topOfSetKey(SetTreeBottomEntity::class, '17'));
    }

    public function testAMoveUnderAnotherTopNamesBothTops(): void
    {
        $object = SetTreeBottomObject::fromEntity(SetTreeBottomEntity::stored(40, 17));
        $object->moveUnder(18);

        $this->assertSame([self::OWN_TOP, self::FOREIGN_TOP], $object->touchedSetKeys());
    }

    public function testTheClaimOfTheTopWritesAGrandchildAndNotAStrangersOne(): void
    {
        TruthSourceRegistry::register(SetTreeBottoms::COLLECTION_KEY, TruthSourceKeys::set(self::OWN_TOP), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $own = SetTreeBottomObject::fromEntity(SetTreeBottomEntity::stored(40, 17));
        DbWriteGuard::guardItemWrite(SetTreeBottoms::COLLECTION_KEY, '40', $own->touchedSetKeys(...), TruthSourceOperation::Update);

        $foreign = SetTreeBottomObject::fromEntity(SetTreeBottomEntity::stored(41, 18));

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '5', and the item's set keys are [6].");
        DbWriteGuard::guardItemWrite(SetTreeBottoms::COLLECTION_KEY, '41', $foreign->touchedSetKeys(...), TruthSourceOperation::Update);
    }

    public function testTheClaimOfTheTopIsRefusedAMoveUnderAnotherTop(): void
    {
        TruthSourceRegistry::register(SetTreeBottoms::COLLECTION_KEY, TruthSourceKeys::set(self::OWN_TOP), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $object = SetTreeBottomObject::fromEntity(SetTreeBottomEntity::stored(40, 17));
        $object->moveUnder(18);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '5', and the item's set keys are [5, 6].");
        DbWriteGuard::guardItemWrite(SetTreeBottoms::COLLECTION_KEY, '40', $object->touchedSetKeys(...), TruthSourceOperation::Update);
    }

    public function testTheClaimOfTheTopIsRefusedARowWhoseParentIsGone(): void
    {
        TruthSourceRegistry::register(SetTreeBottoms::COLLECTION_KEY, TruthSourceKeys::set(self::OWN_TOP), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $orphan = SetTreeBottomObject::fromEntity(SetTreeBottomEntity::stored(41, 99));

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '5', and the item's set keys are [].");
        DbWriteGuard::guardItemWrite(SetTreeBottoms::COLLECTION_KEY, '41', $orphan->touchedSetKeys(...), TruthSourceOperation::Update);
    }

    /**
     * The statement over one set is cut by the set column, and a claim of the top holds it when the
     * value climbs to that top (HIL-1113's door, HIL-1111's climb).
     */
    public function testTheClaimOfTheTopHoldsAStatementOverASetBelowItAndNotAStrangers(): void
    {
        TruthSourceRegistry::register(SetTreeBottoms::COLLECTION_KEY, TruthSourceKeys::set(self::OWN_TOP), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        DbWriteGuard::guardSetWrite(
            SetTreeBottoms::COLLECTION_KEY,
            '17',
            SetTree::climb(SetTreeBottomEntity::class, '17'),
            TruthSourceOperation::Remove,
        );

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("is not a truth source for table 'set_tree_bottoms' set '18': it holds set '5'.");
        DbWriteGuard::guardSetWrite(
            SetTreeBottoms::COLLECTION_KEY,
            '18',
            SetTree::climb(SetTreeBottomEntity::class, '18'),
            TruthSourceOperation::Remove,
        );
    }

    public function testTheClaimOfTheTopIsRefusedAStatementOverASetThatReachesNoTop(): void
    {
        TruthSourceRegistry::register(SetTreeBottoms::COLLECTION_KEY, TruthSourceKeys::set(self::OWN_TOP), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->assertSame([], (SetTree::climb(SetTreeBottomEntity::class, '99'))());

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("is not a truth source for table 'set_tree_bottoms' set '99': it holds set '5'.");
        DbWriteGuard::guardSetWrite(
            SetTreeBottoms::COLLECTION_KEY,
            '99',
            SetTree::climb(SetTreeBottomEntity::class, '99'),
            TruthSourceOperation::Remove,
        );
    }

    public function testTheOwnerOfTheWholeTableHoldsAStatementWithoutClimbing(): void
    {
        $this->expectNotToPerformAssertions();

        TruthSourceRegistry::register(SetTreeBottoms::COLLECTION_KEY, TruthSourceKeys::all(), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        DbWriteGuard::guardSetWrite(
            SetTreeBottoms::COLLECTION_KEY,
            '17',
            static fn(): array => throw new LogicException('the owner of the whole table climbed'),
            TruthSourceOperation::Remove,
        );
    }

    /**
     * @param int $id Middle row id
     * @param int $topId Top row it hangs on
     */
    private function seedMiddle(int $id, int $topId): void
    {
        $middles = Hilos::$db->getObjectCollection(SetTreeMiddles::COLLECTION_KEY);
        $middles[(string)$id] = SetTreeMiddleObject::fromEntity(SetTreeMiddleEntity::stored($id, $topId));
    }

    /**
     * @param int $id Bottom row id
     * @param int $middleId Middle row it hangs on
     */
    private function seedBottom(int $id, int $middleId): void
    {
        $bottoms = Hilos::$db->getObjectCollection(SetTreeBottoms::COLLECTION_KEY);
        $bottoms[(string)$id] = SetTreeBottomObject::fromEntity(SetTreeBottomEntity::stored($id, $middleId));
    }
}

/**
 * DB context fixture mounting the named collections empty, under their own collection keys.
 */
final class SetTreeDbContext extends HilosDbContext
{
    /**
     * @param list<class-string<Objects>> $collectionClasses Collections to mount, in the given order
     */
    public function __construct(private readonly array $collectionClasses = [])
    {
        parent::__construct();
    }

    /**
     * Mounts every named collection empty, so a row nobody seeded is a row that is not there.
     */
    public function configure(): void
    {
        foreach ($this->collectionClasses as $collectionClass) {
            $this->_objectCollections[$collectionClass::COLLECTION_KEY] = $collectionClass::initEmpty();
        }
    }
}

/**
 * The top: rows in nobody's set, which other tables hang their sets off - a person, a room.
 */
final class SetTreeTopEntity extends Entity
{
    public const string id = 'id';

    public const string _table = 'set_tree_top';
    public const string _primary = self::id;
    public const array _columns = [self::id];
    public const array _types = [self::id => PhpType::INTEGER->value];

    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = true;

    public ?int $id = null;
}

/**
 * Object fixture over the top.
 */
final class SetTreeTopObject extends Object_
{
    public const string ENTITY_CLASS = SetTreeTopEntity::class;
}

/**
 * Collection fixture over the top.
 */
final class SetTreeTops extends Objects
{
    public const string OBJECT_CLASS = SetTreeTopObject::class;
    public const string COLLECTION_KEY = 'set_tree_tops';
}

/**
 * The middle floor: hung on the top by a foreign key, and a root other tables hang off in turn.
 */
final class SetTreeMiddleEntity extends Entity
{
    public const string id = 'id';
    public const string top_id = 'top_id';

    public const string _table = 'set_tree_middle';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::top_id];
    public const array _types = [self::id => PhpType::INTEGER->value, self::top_id => PhpType::INTEGER->value];
    public const array _foreign = [self::top_id => SetTreeTopEntity::_table];

    public const string _setVia = self::top_id;
    public const bool _setRoot = true;

    public ?int $id = null;
    public int $top_id;

    /**
     * @param int $id Row id
     * @param int $topId Top row it hangs on
     * @return self A row as it stands in the database
     */
    public static function stored(int $id, int $topId): self
    {
        $entity = new self();
        $entity->id = $id;
        $entity->top_id = $topId;
        $entity->flushRelated();

        return $entity;
    }
}

/**
 * Object fixture over the middle floor, able to move its row under another top without saving.
 */
final class SetTreeMiddleObject extends Object_
{
    public const string ENTITY_CLASS = SetTreeMiddleEntity::class;

    /**
     * @param int $topId Top row an unsaved edit hangs the row on
     */
    public function moveTop(int $topId): void
    {
        $this->entity->top_id = $topId;
    }
}

/**
 * Collection fixture over the middle floor.
 */
final class SetTreeMiddles extends Objects
{
    public const string OBJECT_CLASS = SetTreeMiddleObject::class;
    public const string COLLECTION_KEY = 'set_tree_middles';
}

/**
 * The bottom floor: hung on the middle by a foreign key, two floors below the top.
 */
final class SetTreeBottomEntity extends Entity
{
    public const string id = 'id';
    public const string middle_id = 'middle_id';

    public const string _table = 'set_tree_bottom';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::middle_id];
    public const array _types = [self::id => PhpType::INTEGER->value, self::middle_id => PhpType::INTEGER->value];
    public const array _foreign = [self::middle_id => SetTreeMiddleEntity::_table];

    public const string _setVia = self::middle_id;
    public const bool _setRoot = true;

    public ?int $id = null;
    public int $middle_id;

    /**
     * @param int $id Row id
     * @param int $middleId Middle row it hangs on
     * @return self A row as it stands in the database
     */
    public static function stored(int $id, int $middleId): self
    {
        $entity = new self();
        $entity->id = $id;
        $entity->middle_id = $middleId;
        $entity->flushRelated();

        return $entity;
    }
}

/**
 * Object fixture over the bottom floor, able to move its row under another parent without saving.
 */
final class SetTreeBottomObject extends Object_
{
    public const string ENTITY_CLASS = SetTreeBottomEntity::class;

    /**
     * @param int $middleId Middle row an unsaved edit hangs the row on
     */
    public function moveUnder(int $middleId): void
    {
        $this->entity->middle_id = $middleId;
    }
}

/**
 * Collection fixture over the bottom floor.
 */
final class SetTreeBottoms extends Objects
{
    public const string OBJECT_CLASS = SetTreeBottomObject::class;
    public const string COLLECTION_KEY = 'set_tree_bottoms';
}

/**
 * The leaf: hung on the bottom floor, three floors below the top.
 */
final class SetTreeLeafEntity extends Entity
{
    public const string id = 'id';
    public const string bottom_id = 'bottom_id';

    public const string _table = 'set_tree_leaf';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::bottom_id];
    public const array _types = [self::id => PhpType::INTEGER->value, self::bottom_id => PhpType::INTEGER->value];
    public const array _foreign = [self::bottom_id => SetTreeBottomEntity::_table];

    public const string _setVia = self::bottom_id;
    public const bool _setRoot = false;

    public ?int $id = null;
    public int $bottom_id;
}

/**
 * Object fixture over the leaf.
 */
final class SetTreeLeafObject extends Object_
{
    public const string ENTITY_CLASS = SetTreeLeafEntity::class;
}

/**
 * Collection fixture over the leaf.
 */
final class SetTreeLeaves extends Objects
{
    public const string OBJECT_CLASS = SetTreeLeafObject::class;
    public const string COLLECTION_KEY = 'set_tree_leaves';
}

/**
 * A floor hung on the middle that also carries its top on the row, as a declared short path.
 */
final class SetTreeShortcutEntity extends Entity
{
    public const string id = 'id';
    public const string middle_id = 'middle_id';
    public const string top_id = 'top_id';

    public const string _table = 'set_tree_shortcut';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::middle_id, self::top_id];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::middle_id => PhpType::INTEGER->value,
        self::top_id => PhpType::INTEGER->value,
    ];
    public const array _foreign = [self::middle_id => SetTreeMiddleEntity::_table];

    public const string _setVia = self::middle_id;
    public const string _setShortPath = self::top_id;
    public const bool _setRoot = false;

    public ?int $id = null;
    public int $middle_id;
    public int $top_id;

    /**
     * @param int $id Row id
     * @param int $middleId Middle row it hangs on
     * @param int $topId Top the row carries directly
     * @return self A row as it stands in the database
     */
    public static function stored(int $id, int $middleId, int $topId): self
    {
        $entity = new self();
        $entity->id = $id;
        $entity->middle_id = $middleId;
        $entity->top_id = $topId;
        $entity->flushRelated();

        return $entity;
    }
}

/**
 * Object fixture over the short-path floor, able to move its row under another top without saving.
 */
final class SetTreeShortcutObject extends Object_
{
    public const string ENTITY_CLASS = SetTreeShortcutEntity::class;

    /**
     * @param int $topId Top an unsaved edit moves the row to
     */
    public function moveTop(int $topId): void
    {
        $this->entity->top_id = $topId;
    }
}

/**
 * Collection fixture over the short-path floor.
 */
final class SetTreeShortcuts extends Objects
{
    public const string OBJECT_CLASS = SetTreeShortcutObject::class;
    public const string COLLECTION_KEY = 'set_tree_shortcuts';
}

/**
 * A table whose set column is a soft reference: no foreign key, so its value is the top.
 */
final class SetTreeSoftEntity extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';

    public const string _table = 'set_tree_soft';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::user_id];
    public const array _types = [self::id => PhpType::INTEGER->value, self::user_id => PhpType::INTEGER->value];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;
}

/**
 * A table hung by a foreign key on a table this installation did not mount.
 */
final class SetTreeStrayEntity extends Entity
{
    public const string id = 'id';
    public const string elsewhere_id = 'elsewhere_id';

    public const string _table = 'set_tree_stray';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::elsewhere_id];
    public const array _types = [self::id => PhpType::INTEGER->value, self::elsewhere_id => PhpType::INTEGER->value];
    public const array _foreign = [self::elsewhere_id => 'set_tree_elsewhere'];

    public const string _setVia = self::elsewhere_id;
    public const bool _setRoot = false;
}
