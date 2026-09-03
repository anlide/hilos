<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\UndeclaredSetOwnershipException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\SetOwnershipGuard;
use Hilos\Hilos;
use Hilos\Tests\Unit\FrameworkEntitySetOwnershipTest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the startup gate that asks whose set a mounted table is part of.
 *
 * The gate is judged over made-up Entities rather than over the framework's own, because what is
 * worth pinning is the shape of a refusal: which declarations it demands, what it does with a
 * column that is not a column, and that it names everything at fault in one breath instead of
 * stopping at the first. The framework's real Entities are checked for having the declarations at
 * all by {@see FrameworkEntitySetOwnershipTest}, which needs no installation.
 *
 * Every case mounts a context of its own, because the gate reads the collections an installation
 * mounted and nothing else.
 */
final class SetOwnershipGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$db = null;

        parent::tearDown();
    }

    public function testAnInstallationThatMountedNothingIsNotJudged(): void
    {
        $this->expectNotToPerformAssertions();

        SetOwnershipGuard::assertMountedSetsDeclared();
    }

    public function testTheFrameworksOwnCollectionsPassItsOwnGate(): void
    {
        $this->expectNotToPerformAssertions();

        // The one case not made of fixtures, and the reason it is here: the sibling test asks
        // whether each framework Entity declares its pair, while this asks the gate the question
        // a starting node asks - including the cross-check between two of them.
        Hilos::$db = new SetGuardFrameworkDbContext();
        Hilos::$db->configure();

        SetOwnershipGuard::assertMountedSetsDeclared();
    }

    public function testAProperlyDeclaredSetPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mount([SetGuardParents::class, SetGuardChildren::class]);

        SetOwnershipGuard::assertMountedSetsDeclared();
    }

    public function testATableDeclaringNeitherHalfIsRefused(): void
    {
        $this->mount([SetGuardSilents::class]);

        $this->expectException(UndeclaredSetOwnershipException::class);
        $this->expectExceptionMessage(SetGuardSilentEntity::class . ' declares no ' . Entity::META_SET_VIA);

        SetOwnershipGuard::assertMountedSetsDeclared();
    }

    public function testAnOwnerColumnTheTableDoesNotHaveIsRefused(): void
    {
        $this->mount([SetGuardStrangeColumns::class]);

        $this->expectException(UndeclaredSetOwnershipException::class);
        $this->expectExceptionMessage(
            SetGuardStrangeColumnEntity::class . " names column 'owner_id' in " . Entity::META_SET_VIA,
        );

        SetOwnershipGuard::assertMountedSetsDeclared();
    }

    public function testANonBooleanRootIsRefused(): void
    {
        $this->mount([SetGuardLooseRoots::class]);

        $this->expectException(UndeclaredSetOwnershipException::class);
        $this->expectExceptionMessage(
            SetGuardLooseRootEntity::class . ' declares a non-boolean ' . Entity::META_SET_ROOT,
        );

        SetOwnershipGuard::assertMountedSetsDeclared();
    }

    public function testASetHungOnATableThatIsNoRootIsRefused(): void
    {
        $this->mount([SetGuardLeaves::class, SetGuardStrays::class]);

        $this->expectException(UndeclaredSetOwnershipException::class);
        $this->expectExceptionMessage(
            SetGuardStrayEntity::class . ' hangs its set on set_guard_leaf, which does not declare itself a set root',
        );

        SetOwnershipGuard::assertMountedSetsDeclared();
    }

    public function testTheCrossCheckFollowsTheForeignEntryAndNotTheColumnName(): void
    {
        $this->expectNotToPerformAssertions();

        // The child's column is called `leaf_id` while its `_foreign` hangs it on the parent, and
        // only the parent declares itself a root. Reading the column name would refuse this.
        $this->mount([SetGuardParents::class, SetGuardLeaves::class, SetGuardMisnamedColumns::class]);

        SetOwnershipGuard::assertMountedSetsDeclared();
    }

    public function testEveryFindingArrivesInOneRefusal(): void
    {
        $this->mount([SetGuardSilents::class, SetGuardStrangeColumns::class, SetGuardLooseRoots::class]);

        $message = '';
        try {
            SetOwnershipGuard::assertMountedSetsDeclared();
            $this->fail('A mounted context of three broken tables was accepted');
        } catch (UndeclaredSetOwnershipException $refusal) {
            $message = $refusal->getMessage();
        }

        $this->assertStringContainsString(SetGuardSilentEntity::class, $message);
        $this->assertStringContainsString(SetGuardStrangeColumnEntity::class, $message);
        $this->assertStringContainsString(SetGuardLooseRootEntity::class, $message);
    }

    /**
     * Mounts a context carrying exactly the given object collections.
     *
     * @param list<class-string<Objects>> $collectionClasses Collections this installation declares
     */
    private function mount(array $collectionClasses): void
    {
        Hilos::$db = new SetGuardDbContext($collectionClasses);
        Hilos::$db->configure();
    }
}

/**
 * DB context fixture mounting the framework's own collections and nothing else.
 */
final class SetGuardFrameworkDbContext extends HilosDbContext
{
}

/**
 * DB context fixture mounting whichever collections a case names, and nothing else.
 */
final class SetGuardDbContext extends DbContext
{
    /**
     * @param list<class-string<Objects>> $collectionClasses Collections to mount, in the given order
     */
    public function __construct(private readonly array $collectionClasses = [])
    {
        parent::__construct();
    }

    /**
     * Mounts the named collections under their own class names as keys.
     */
    public function configure(): void
    {
        foreach ($this->collectionClasses as $collectionClass) {
            $this->_objectCollections[$collectionClass] = $collectionClass::initDB(Objects::LAZY_STRATEGY_KEY);
        }
    }
}

/**
 * A root: standalone rows of its own, and other tables may hang their sets off it.
 */
final class SetGuardParentEntity extends Entity
{
    public const string id = 'id';

    public const string _table = 'set_guard_parent';
    public const string _primary = self::id;
    public const array _columns = [self::id];
    public const array _types = [self::id => PhpType::INTEGER->value];

    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = true;
}

/**
 * A well-declared child: its set is cut by a column it has, hung on a table that is a root.
 */
final class SetGuardChildEntity extends Entity
{
    public const string id = 'id';
    public const string parent_id = 'parent_id';

    public const string _table = 'set_guard_child';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::parent_id];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::parent_id => PhpType::INTEGER->value,
    ];
    public const array _foreign = [self::parent_id => SetGuardParentEntity::_table];

    public const string _setVia = self::parent_id;
    public const bool _setRoot = false;
}

/**
 * A table that declares neither half.
 */
final class SetGuardSilentEntity extends Entity
{
    public const string id = 'id';

    public const string _table = 'set_guard_silent';
    public const string _primary = self::id;
    public const array _columns = [self::id];
    public const array _types = [self::id => PhpType::INTEGER->value];
}

/**
 * A table cutting its set by a column that is not among its own.
 */
final class SetGuardStrangeColumnEntity extends Entity
{
    public const string id = 'id';

    public const string _table = 'set_guard_strange_column';
    public const string _primary = self::id;
    public const array _columns = [self::id];
    public const array _types = [self::id => PhpType::INTEGER->value];

    public const string _setVia = 'owner_id';
    public const bool _setRoot = false;
}

/**
 * A table answering the root question with something that is not a yes or a no.
 */
final class SetGuardLooseRootEntity extends Entity
{
    public const string id = 'id';

    public const string _table = 'set_guard_loose_root';
    public const string _primary = self::id;
    public const array _columns = [self::id];
    public const array _types = [self::id => PhpType::INTEGER->value];

    public const string _setVia = Entity::SET_STANDALONE;
    public const string _setRoot = 'yes';
}

/**
 * A table that is nobody's root, used as the wrong thing to hang a set on.
 */
final class SetGuardLeafEntity extends Entity
{
    public const string id = 'id';

    public const string _table = 'set_guard_leaf';
    public const string _primary = self::id;
    public const array _columns = [self::id];
    public const array _types = [self::id => PhpType::INTEGER->value];

    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;
}

/**
 * A child hanging its set on a table that never declared itself a root.
 */
final class SetGuardStrayEntity extends Entity
{
    public const string id = 'id';
    public const string leaf_id = 'leaf_id';

    public const string _table = 'set_guard_stray';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::leaf_id];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::leaf_id => PhpType::INTEGER->value,
    ];
    public const array _foreign = [self::leaf_id => SetGuardLeafEntity::_table];

    public const string _setVia = self::leaf_id;
    public const bool _setRoot = false;
}

/**
 * A child whose column is named after one table while its foreign entry names another.
 */
final class SetGuardMisnamedColumnEntity extends Entity
{
    public const string id = 'id';
    public const string leaf_id = 'leaf_id';

    public const string _table = 'set_guard_misnamed_column';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::leaf_id];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::leaf_id => PhpType::INTEGER->value,
    ];
    public const array _foreign = [self::leaf_id => SetGuardParentEntity::_table];

    public const string _setVia = self::leaf_id;
    public const bool _setRoot = false;
}

/**
 * @extends Object_<SetGuardParentEntity>
 */
final class SetGuardParent extends Object_
{
    public const string ENTITY_CLASS = SetGuardParentEntity::class;
}

/**
 * @extends Object_<SetGuardChildEntity>
 */
final class SetGuardChild extends Object_
{
    public const string ENTITY_CLASS = SetGuardChildEntity::class;
}

/**
 * @extends Object_<SetGuardSilentEntity>
 */
final class SetGuardSilent extends Object_
{
    public const string ENTITY_CLASS = SetGuardSilentEntity::class;
}

/**
 * @extends Object_<SetGuardStrangeColumnEntity>
 */
final class SetGuardStrangeColumn extends Object_
{
    public const string ENTITY_CLASS = SetGuardStrangeColumnEntity::class;
}

/**
 * @extends Object_<SetGuardLooseRootEntity>
 */
final class SetGuardLooseRoot extends Object_
{
    public const string ENTITY_CLASS = SetGuardLooseRootEntity::class;
}

/**
 * @extends Object_<SetGuardLeafEntity>
 */
final class SetGuardLeaf extends Object_
{
    public const string ENTITY_CLASS = SetGuardLeafEntity::class;
}

/**
 * @extends Object_<SetGuardStrayEntity>
 */
final class SetGuardStray extends Object_
{
    public const string ENTITY_CLASS = SetGuardStrayEntity::class;
}

/**
 * @extends Object_<SetGuardMisnamedColumnEntity>
 */
final class SetGuardMisnamedColumn extends Object_
{
    public const string ENTITY_CLASS = SetGuardMisnamedColumnEntity::class;
}

/**
 * @extends Objects<SetGuardParent>
 */
final class SetGuardParents extends Objects
{
    public const string OBJECT_CLASS = SetGuardParent::class;
}

/**
 * @extends Objects<SetGuardChild>
 */
final class SetGuardChildren extends Objects
{
    public const string OBJECT_CLASS = SetGuardChild::class;
}

/**
 * @extends Objects<SetGuardSilent>
 */
final class SetGuardSilents extends Objects
{
    public const string OBJECT_CLASS = SetGuardSilent::class;
}

/**
 * @extends Objects<SetGuardStrangeColumn>
 */
final class SetGuardStrangeColumns extends Objects
{
    public const string OBJECT_CLASS = SetGuardStrangeColumn::class;
}

/**
 * @extends Objects<SetGuardLooseRoot>
 */
final class SetGuardLooseRoots extends Objects
{
    public const string OBJECT_CLASS = SetGuardLooseRoot::class;
}

/**
 * @extends Objects<SetGuardLeaf>
 */
final class SetGuardLeaves extends Objects
{
    public const string OBJECT_CLASS = SetGuardLeaf::class;
}

/**
 * @extends Objects<SetGuardStray>
 */
final class SetGuardStrays extends Objects
{
    public const string OBJECT_CLASS = SetGuardStray::class;
}

/**
 * @extends Objects<SetGuardMisnamedColumn>
 */
final class SetGuardMisnamedColumns extends Objects
{
    public const string OBJECT_CLASS = SetGuardMisnamedColumn::class;
}
