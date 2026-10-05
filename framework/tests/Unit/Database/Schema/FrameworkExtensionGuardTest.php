<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Actions\Item\VerifierCircleMemberActions;
use Hilos\Database\Context\FrameworkExtension;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\AuthBlocks as EntityAuthBlocks;
use Hilos\Database\Entity\Collection\VerifierCircleMembers as EntityVerifierCircleMembers;
use Hilos\Database\Entity\Item\AuthBlock as EntityAuthBlock;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\VerifierCircleMember as EntityVerifierCircleMember;
use Hilos\Database\Exception\IncompleteFrameworkExtensionException;
use Hilos\Database\Object\Collection\AuthBlocks as ObjectAuthBlocks;
use Hilos\Database\Object\Collection\Users as ObjectUsers;
use Hilos\Database\Object\Collection\VerifierCircleMembers as ObjectVerifierCircleMembers;
use Hilos\Database\Object\Item\AuthBlock as ObjectAuthBlock;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\Database\View\Collection\AuthBlocks as DbCollectionAuthBlocks;
use Hilos\Database\View\Item\AuthBlock as ViewAuthBlock;
use Hilos\Database\View\Item\VerifierCircleMember as ViewVerifierCircleMember;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMemberActions;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMemberEntities;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMemberEntity;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMemberObject;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMemberObjects;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMembers;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMembersActions;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMembersDbContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the startup gate that asks whether the chain under a framework key is whole.
 *
 * The green case is the test chain over the verifier circle, `Fixtures/Extension/`, mounted by
 * its own context. Every refusal is staged over that chain: a fixture at the end of this file
 * extends a green class and breaks exactly one thing, re-pointing the links down to the break so
 * that the break is the only finding. The purged-table case takes a short chain over the auth
 * blocks instead, the framework table with the fewest layers under a whole-table verdict.
 *
 * No database is needed: configure() only constructs the collections, and the gate reads
 * constants and the mounted map.
 */
final class FrameworkExtensionGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$db = null;

        parent::tearDown();
    }

    public function testAProcessWithoutADatabaseContextIsNotJudged(): void
    {
        $this->expectNotToPerformAssertions();

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     */
    public function testAnInstallationThatExtendsNoKeyIsNotJudged(): void
    {
        $this->expectNotToPerformAssertions();

        // The framework's own chains under every one of its keys: each object collection is the
        // one its view names, and none of the keys is extended, so nothing is walked.
        $this->mount([]);

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     */
    public function testAWholeChainUnderTheFrameworkKeyPasses(): void
    {
        $this->expectNotToPerformAssertions();

        Hilos::$db = new NotedMembersDbContext();
        Hilos::$db->configure();

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testAnActionLayerLeftTheFrameworksIsRefused(): void
    {
        $this->mount([
            HilosDbContext::verifierCircle => new FrameworkExtension(NotedMembers::class, NotedMembersActions::class),
        ]);

        // The layer left undeclared stays the framework's, and the framework's class is what the
        // finding names as mounted.
        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            'key [verifierCircle]: the mounted item actions ' . VerifierCircleMemberActions::class
            . " does not extend the framework's " . VerifierCircleMemberActions::class,
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testAnObjectCollectionStillNamingTheFrameworksEntityCollectionIsRefused(): void
    {
        $this->mountCircle(HalfLinkedMembers::class);

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            HalfLinkedObjects::class . '::ENTITY_COLLECTION_CLASS names ' . EntityVerifierCircleMembers::class
            . ' as the entity collection',
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testAViewStillNamingTheFrameworksItemIsRefused(): void
    {
        $this->mountCircle(BareItemMembers::class);

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            BareItemMembers::class . '::DB_ITEM_CLASS names ' . ViewVerifierCircleMember::class . ' as the view item',
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testTwoConstantsNamingTwoEntitiesAreRefused(): void
    {
        $this->mountCircle(StrayMembers::class);

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            StrayEntities::class . '::ENTITY_CLASS names ' . StrayEntity::class . ' while ' . NotedMemberObject::class
            . '::ENTITY_CLASS names ' . NotedMemberEntity::class,
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testAFrameworkKeyWrittenOverIsRefused(): void
    {
        Hilos::$db = new WrittenOverDbContext();
        Hilos::$db->configure();

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            'key [verifierCircle]: the mounted object collection ' . NotedMemberObjects::class . ' is not',
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testAnEntityThatLeftTheFrameworksTableIsRefused(): void
    {
        $this->mountCircle(MovedTableMembers::class);

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            MovedTableEntity::class . '::' . Entity::META_TABLE . " does not keep the framework's "
            . EntityVerifierCircleMember::class . '::' . Entity::META_TABLE,
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testAnEntityThatLostAColumnOfTheBaseIsRefused(): void
    {
        $this->mountCircle(LostColumnMembers::class);

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            LostColumnEntity::class . '::' . Entity::META_COLUMNS . " does not keep the framework's "
            . EntityVerifierCircleMember::class . '::' . Entity::META_COLUMNS
            . ': lost or changed [' . EntityVerifierCircleMember::identifier . ']; compose it from parent::' . Entity::META_COLUMNS,
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testAnEntityThatChangedATypeOfTheBaseIsRefused(): void
    {
        $this->mountCircle(RetypedColumnMembers::class);

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            RetypedColumnEntity::class . '::' . Entity::META_TYPES . " does not keep the framework's "
            . EntityVerifierCircleMember::class . '::' . Entity::META_TYPES
            . ': lost or changed [' . EntityVerifierCircleMember::identifier . ']',
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testAnEntityThatLostAVerdictOfTheBaseIsRefused(): void
    {
        $this->mountCircle(LostVerdictMembers::class);

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            LostVerdictEntity::class . '::' . Entity::META_PII . " does not keep the framework's "
            . EntityVerifierCircleMember::class . '::' . Entity::META_PII
            . ': lost or changed [' . EntityVerifierCircleMember::identifier . ']',
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testAnObjectCollectionUnderAnotherKeyIsRefused(): void
    {
        $this->mountCircle(RekeyedMembers::class);

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            RekeyedObjects::class . "::COLLECTION_KEY does not keep the framework's " . ObjectVerifierCircleMembers::class
            . '::COLLECTION_KEY',
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testANewColumnWithoutAVerdictIsRefused(): void
    {
        $this->mountCircle(MuteColumnMembers::class);

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            MuteColumnEntity::class . ' says nothing about its column [' . MuteColumnEntity::mood . '], which is neither in '
            . Entity::META_PII . ' nor in ' . Entity::META_PII_NOT_PERSONAL,
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testANewColumnCalledBothPersonalAndNotPersonalIsRefused(): void
    {
        $this->mountCircle(DoubleVerdictMembers::class);

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            DoubleVerdictEntity::class . ' names its column [' . NotedMemberEntity::note . '] both personal and not personal',
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testABaseColumnNamedPersonalWhileStillNotPersonalIsRefused(): void
    {
        $this->mountCircle(RejudgedColumnMembers::class);

        // The double verdict is asked over the whole verdict, not over the new columns alone: the
        // base's list of non-personal columns is inherited, and the subclass named one of them
        // personal on top of it.
        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            RejudgedColumnEntity::class . ' names its column [' . EntityVerifierCircleMember::identity_type
            . '] both personal and not personal',
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     */
    public function testAPurgedTableCoversANewColumnWithoutAVerdictOfItsOwn(): void
    {
        $this->expectNotToPerformAssertions();

        // The framework registers the auth blocks without action layers and purges the table
        // whole; the subclass adds a column and restates no verdict, and the purge covers it.
        $this->mount([HilosDbContext::authBlocks => new FrameworkExtension(TaggedBlocks::class)]);

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the guard refuses the chain, which is the point
     */
    public function testTwoChainsOverOneTableAreRefused(): void
    {
        Hilos::$db = new SecondKeyDbContext();
        Hilos::$db->configure();

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            'table ' . EntityVerifierCircleMember::_table . ' is mounted by ' . NotedMemberObjects::class
            . ' [' . HilosDbContext::verifierCircle . '] and ' . CopiedCircleObjects::class
            . ' [' . SecondKeyDbContext::circleCopy . ']',
        );

        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     * @throws IncompleteFrameworkExtensionException When the same table is mounted twice
     */
    public function testSameCollectionUnderTwoActualKeysNamesBothKeys(): void
    {
        Hilos::$db = new class extends HilosDbContext {
            public function configure(): void
            {
                parent::configure();
                $this->_objectCollections['alias'] = $this->_objectCollections[self::users];
            }
        };
        Hilos::$db->configure();

        $this->expectException(IncompleteFrameworkExtensionException::class);
        $this->expectExceptionMessage(
            ObjectUsers::class . ' [' . HilosDbContext::users . '] and '
            . ObjectUsers::class . ' [alias]',
        );
        FrameworkExtensionGuard::assertMountedExtensionsWhole();
    }

    /**
     * @throws HilosException When the context refuses to configure
     */
    public function testEveryFindingIsNamedInOneRefusal(): void
    {
        // A view that keeps the framework's item, declared without its item actions: two layers
        // still the framework's, one refusal naming both.
        $this->mount([
            HilosDbContext::verifierCircle => new FrameworkExtension(BareItemMembers::class, NotedMembersActions::class),
        ]);

        $message = '';
        try {
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
            $this->fail('A chain with two layers still the framework\'s was accepted');
        } catch (IncompleteFrameworkExtensionException $refusal) {
            $message = $refusal->getMessage();
        }

        $this->assertStringStartsWith('This node refuses to start over a half-extended framework entity: ', $message);
        $this->assertStringContainsString('the mounted item actions ' . VerifierCircleMemberActions::class, $message);
        $this->assertStringContainsString(BareItemMembers::class . '::DB_ITEM_CLASS names', $message);
    }

    /**
     * Mounts the framework's keys with the given declarations over them.
     *
     * @param array<string, FrameworkExtension> $extensions Project chains per framework key
     * @throws HilosException When the context refuses to configure
     */
    private function mount(array $extensions): void
    {
        Hilos::$db = new ExtensionGuardDbContext($extensions);
        Hilos::$db->configure();
    }

    /**
     * Mounts the verifier circle with the given view collection over it, and the test chain's two
     * action layers - the whole declaration but for the view, so that the view's chain is what a
     * case breaks.
     *
     * @param class-string<NotedMembers> $members View collection to mount under the verifier circle key
     * @throws HilosException When the context refuses to configure
     */
    private function mountCircle(string $members): void
    {
        $this->mount([
            HilosDbContext::verifierCircle => new FrameworkExtension(
                $members,
                NotedMembersActions::class,
                NotedMemberActions::class,
            ),
        ]);
    }
}

/**
 * A context declaring whatever chains a case hands it, over the framework's keys.
 */
final class ExtensionGuardDbContext extends HilosDbContext
{
    /**
     * @param array<string, FrameworkExtension> $extensions Project chains per framework key
     */
    public function __construct(private readonly array $extensions)
    {
        parent::__construct();
    }

    /**
     * @return array<string, FrameworkExtension> The chains the case handed over
     */
    protected function frameworkExtensions(): array
    {
        return [...parent::frameworkExtensions(), ...$this->extensions];
    }
}

/**
 * A context that writes the test chain's object collection over the framework's key after the
 * framework mounted it, declaring no extension: the view under the key stays the framework's and
 * names another object collection than the one mounted.
 */
final class WrittenOverDbContext extends HilosDbContext
{
    /**
     * @throws HilosException When the framework's own mount refuses
     */
    public function configure(): void
    {
        parent::configure();
        $this->_objectCollections[self::verifierCircle] = NotedMemberObjects::initDB(Objects::LAZY_STRATEGY_KEY);
    }
}

/**
 * The green context plus a second chain over the same table under a key of the project's own.
 */
final class SecondKeyDbContext extends NotedMembersDbContext
{
    public const string circleCopy = 'circleCopy';

    /**
     * @throws HilosException When a mount refuses
     */
    public function configure(): void
    {
        parent::configure();
        $this->_objectCollections[self::circleCopy] = CopiedCircleObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::circleCopy, CopiedCircle::class);
    }
}

/**
 * A view that names the framework's item where the chain has its own.
 */
final class BareItemMembers extends NotedMembers
{
    public const string DB_ITEM_CLASS = ViewVerifierCircleMember::class;
}

/**
 * An object collection that names the framework's entity collection where the chain has its own.
 */
final class HalfLinkedObjects extends NotedMemberObjects
{
    public const string ENTITY_COLLECTION_CLASS = EntityVerifierCircleMembers::class;
}

/**
 * The view over the half-linked object collection.
 */
final class HalfLinkedMembers extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = HalfLinkedObjects::class;
}

/**
 * A second Entity subclass, so that the entity collection can name one and the Object another.
 */
final class StrayEntity extends NotedMemberEntity
{
}

/**
 * An entity collection naming the stray Entity.
 */
final class StrayEntities extends NotedMemberEntities
{
    public const string ENTITY_CLASS = StrayEntity::class;
}

/**
 * An object collection whose entity collection names the stray Entity while its Object keeps naming
 * the chain's.
 */
final class StrayObjects extends NotedMemberObjects
{
    public const string ENTITY_COLLECTION_CLASS = StrayEntities::class;
}

/**
 * The view over the object collection with two Entities named.
 */
final class StrayMembers extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = StrayObjects::class;
}

/**
 * An Entity that names a table of its own.
 */
final class MovedTableEntity extends NotedMemberEntity
{
    public const string _table = 'project_circle';
}

/**
 * The entity collection of the moved-table chain.
 */
final class MovedTableEntities extends NotedMemberEntities
{
    public const string ENTITY_CLASS = MovedTableEntity::class;
}

/**
 * The Object of the moved-table chain.
 */
final class MovedTableObject extends NotedMemberObject
{
    public const string ENTITY_CLASS = MovedTableEntity::class;
}

/**
 * The object collection of the moved-table chain.
 */
final class MovedTableObjects extends NotedMemberObjects
{
    public const string OBJECT_CLASS = MovedTableObject::class;
    public const string ENTITY_COLLECTION_CLASS = MovedTableEntities::class;
}

/**
 * The view of the moved-table chain.
 */
final class MovedTableMembers extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = MovedTableObjects::class;
}

/**
 * An Entity that restated the base's columns by hand and left one out.
 */
final class LostColumnEntity extends NotedMemberEntity
{
    public const array _columns = [self::id, self::identity_type, self::note];
}

/**
 * The entity collection of the lost-column chain.
 */
final class LostColumnEntities extends NotedMemberEntities
{
    public const string ENTITY_CLASS = LostColumnEntity::class;
}

/**
 * The Object of the lost-column chain.
 */
final class LostColumnObject extends NotedMemberObject
{
    public const string ENTITY_CLASS = LostColumnEntity::class;
}

/**
 * The object collection of the lost-column chain.
 */
final class LostColumnObjects extends NotedMemberObjects
{
    public const string OBJECT_CLASS = LostColumnObject::class;
    public const string ENTITY_COLLECTION_CLASS = LostColumnEntities::class;
}

/**
 * The view of the lost-column chain.
 */
final class LostColumnMembers extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = LostColumnObjects::class;
}

/**
 * An Entity that gives a column of the base another type.
 */
final class RetypedColumnEntity extends NotedMemberEntity
{
    public const array _types = [...parent::_types, self::identifier => PhpType::INTEGER->value];
}

/**
 * The entity collection of the retyped-column chain.
 */
final class RetypedColumnEntities extends NotedMemberEntities
{
    public const string ENTITY_CLASS = RetypedColumnEntity::class;
}

/**
 * The Object of the retyped-column chain.
 */
final class RetypedColumnObject extends NotedMemberObject
{
    public const string ENTITY_CLASS = RetypedColumnEntity::class;
}

/**
 * The object collection of the retyped-column chain.
 */
final class RetypedColumnObjects extends NotedMemberObjects
{
    public const string OBJECT_CLASS = RetypedColumnObject::class;
    public const string ENTITY_COLLECTION_CLASS = RetypedColumnEntities::class;
}

/**
 * The view of the retyped-column chain.
 */
final class RetypedColumnMembers extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = RetypedColumnObjects::class;
}

/**
 * An Entity that restated the verdict by hand and dropped the base's column from it.
 */
final class LostVerdictEntity extends NotedMemberEntity
{
    public const array _pii = [self::note => AnonymizationStrategy::NULLIFY];
}

/**
 * The entity collection of the lost-verdict chain.
 */
final class LostVerdictEntities extends NotedMemberEntities
{
    public const string ENTITY_CLASS = LostVerdictEntity::class;
}

/**
 * The Object of the lost-verdict chain.
 */
final class LostVerdictObject extends NotedMemberObject
{
    public const string ENTITY_CLASS = LostVerdictEntity::class;
}

/**
 * The object collection of the lost-verdict chain.
 */
final class LostVerdictObjects extends NotedMemberObjects
{
    public const string OBJECT_CLASS = LostVerdictObject::class;
    public const string ENTITY_COLLECTION_CLASS = LostVerdictEntities::class;
}

/**
 * The view of the lost-verdict chain.
 */
final class LostVerdictMembers extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = LostVerdictObjects::class;
}

/**
 * An object collection under a collection key of its own.
 */
final class RekeyedObjects extends NotedMemberObjects
{
    public const string COLLECTION_KEY = 'circleOfTheProject';
}

/**
 * The view over the re-keyed object collection.
 */
final class RekeyedMembers extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = RekeyedObjects::class;
}

/**
 * An Entity that adds a column and says nothing about it.
 */
final class MuteColumnEntity extends NotedMemberEntity
{
    public const string mood = 'mood';

    public const array _columns = [...parent::_columns, self::mood];

    public const array _types = [...parent::_types, self::mood => PhpType::STRING->value];
}

/**
 * The entity collection of the mute-column chain.
 */
final class MuteColumnEntities extends NotedMemberEntities
{
    public const string ENTITY_CLASS = MuteColumnEntity::class;
}

/**
 * The Object of the mute-column chain.
 */
final class MuteColumnObject extends NotedMemberObject
{
    public const string ENTITY_CLASS = MuteColumnEntity::class;
}

/**
 * The object collection of the mute-column chain.
 */
final class MuteColumnObjects extends NotedMemberObjects
{
    public const string OBJECT_CLASS = MuteColumnObject::class;
    public const string ENTITY_COLLECTION_CLASS = MuteColumnEntities::class;
}

/**
 * The view of the mute-column chain.
 */
final class MuteColumnMembers extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = MuteColumnObjects::class;
}

/**
 * An Entity that calls its own column both personal and not personal.
 */
final class DoubleVerdictEntity extends NotedMemberEntity
{
    public const array _piiNotPersonal = [...parent::_piiNotPersonal, self::note];
}

/**
 * The entity collection of the double-verdict chain.
 */
final class DoubleVerdictEntities extends NotedMemberEntities
{
    public const string ENTITY_CLASS = DoubleVerdictEntity::class;
}

/**
 * The Object of the double-verdict chain.
 */
final class DoubleVerdictObject extends NotedMemberObject
{
    public const string ENTITY_CLASS = DoubleVerdictEntity::class;
}

/**
 * The object collection of the double-verdict chain.
 */
final class DoubleVerdictObjects extends NotedMemberObjects
{
    public const string OBJECT_CLASS = DoubleVerdictObject::class;
    public const string ENTITY_COLLECTION_CLASS = DoubleVerdictEntities::class;
}

/**
 * The view of the double-verdict chain.
 */
final class DoubleVerdictMembers extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = DoubleVerdictObjects::class;
}

/**
 * An Entity that names a column of the base personal while the inherited list still calls it not personal.
 */
final class RejudgedColumnEntity extends NotedMemberEntity
{
    public const array _pii = [...parent::_pii, self::identity_type => AnonymizationStrategy::NULLIFY];
}

/**
 * The entity collection of the rejudged-column chain.
 */
final class RejudgedColumnEntities extends NotedMemberEntities
{
    public const string ENTITY_CLASS = RejudgedColumnEntity::class;
}

/**
 * The Object of the rejudged-column chain.
 */
final class RejudgedColumnObject extends NotedMemberObject
{
    public const string ENTITY_CLASS = RejudgedColumnEntity::class;
}

/**
 * The object collection of the rejudged-column chain.
 */
final class RejudgedColumnObjects extends NotedMemberObjects
{
    public const string OBJECT_CLASS = RejudgedColumnObject::class;
    public const string ENTITY_COLLECTION_CLASS = RejudgedColumnEntities::class;
}

/**
 * The view of the rejudged-column chain.
 */
final class RejudgedColumnMembers extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = RejudgedColumnObjects::class;
}

/**
 * An Entity over the purged auth blocks table with a column of its own and no verdict restated:
 * the purge covers the column.
 */
final class TaggedBlockEntity extends EntityAuthBlock
{
    public const string tag = 'tag';

    public const array _columns = [...parent::_columns, self::tag];

    public const array _types = [...parent::_types, self::tag => PhpType::STRING->value];
}

/**
 * The entity collection of the tagged-blocks chain.
 */
final class TaggedBlockEntities extends EntityAuthBlocks
{
    public const string ENTITY_CLASS = TaggedBlockEntity::class;
}

/**
 * The Object of the tagged-blocks chain.
 */
final class TaggedBlockObject extends ObjectAuthBlock
{
    public const string ENTITY_CLASS = TaggedBlockEntity::class;
}

/**
 * The object collection of the tagged-blocks chain.
 */
final class TaggedBlockObjects extends ObjectAuthBlocks
{
    public const string OBJECT_CLASS = TaggedBlockObject::class;
    public const string ENTITY_COLLECTION_CLASS = TaggedBlockEntities::class;
}

/**
 * The view item of the tagged-blocks chain.
 */
final class TaggedBlock extends ViewAuthBlock
{
}

/**
 * The view of the tagged-blocks chain: the framework registers no action layer for the key, so
 * the view is the whole declaration.
 */
final class TaggedBlocks extends DbCollectionAuthBlocks
{
    public const string DB_ITEM_CLASS = TaggedBlock::class;
    public const string OBJECT_COLLECTION_CLASS = TaggedBlockObjects::class;
}

/**
 * The green object collection under a collection key of the project's own: a second chain over the
 * framework's table, which is the anti-pattern of mounting a subclass under another key.
 */
final class CopiedCircleObjects extends NotedMemberObjects
{
    public const string COLLECTION_KEY = SecondKeyDbContext::circleCopy;
}

/**
 * The view over the copied circle.
 */
final class CopiedCircle extends NotedMembers
{
    public const string OBJECT_COLLECTION_CLASS = CopiedCircleObjects::class;
}
