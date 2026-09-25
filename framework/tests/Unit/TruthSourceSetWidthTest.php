<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use PHPUnit\Framework\TestCase;

/**
 * HIL-1109: the third width of a claim - the rows of one set - answered by the row's own set column.
 *
 * An agent holding a set edits a row of its set and is refused a row of another; the refusal is
 * the truth source's, asked first, as it is for a foreign key under the two older widths.
 *
 * HIL-1113 asks the same width of one statement over every row of a set: the owner of the set
 * writes its set in one go, and is refused a statement over another set or across the table.
 */
final class TruthSourceSetWidthTest extends TestCase
{
    private const string COLLECTION = 'unit_set_width';
    private const string AGENT_A = 'unit_set_agent:a';
    private const string AGENT_B = 'unit_set_agent:b';
    private const string OWN_SET = '42';
    private const string FOREIGN_SET = '7';
    private const int OWN_OWNER_ID = 42;
    private const int FOREIGN_OWNER_ID = 7;

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::AGENT_A);
        TruthSourceRegistry::unregisterAgent(self::AGENT_B);
        TruthSourceRegistry::unregisterAgent(SetWidthDeclaredAgent::AGENT_TYPE);
        // The declared claim is a reader interest too, so it is given back beside the claim itself.
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(SetWidthDeclaredAgent::AGENT_TYPE));

        parent::tearDown();
    }

    public function testSetWidthIsAThirdNamedState(): void
    {
        $keys = TruthSourceKeys::set(self::OWN_SET);

        $this->assertTrue($keys->coversSet());
        $this->assertFalse($keys->coversEveryKey());
        $this->assertFalse($keys->coversNoKey());
        $this->assertSame([], $keys->listedKeys());
        $this->assertFalse($keys->covers('1'));
        $this->assertSame(self::OWN_SET, $keys->setKey());
        $this->assertFalse(TruthSourceKeys::all()->coversSet());
        $this->assertFalse(TruthSourceKeys::listed('1')->coversSet());
        $this->assertTrue(TruthSourceKeys::listed()->coversNoKey());
    }

    public function testOnlyASetClaimHasASetKey(): void
    {
        $this->expectException(LogicException::class);
        TruthSourceKeys::listed('1')->setKey();
    }

    public function testAnEmptySetKeyNamesNobody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TruthSourceKeys::set('');
    }

    public function testCoversRowAnswersEachWidthByItsOwnQuestion(): void
    {
        $this->assertTrue(TruthSourceKeys::all()->coversRow('1', []));
        $this->assertTrue(TruthSourceKeys::all()->coversRow('1', [self::FOREIGN_SET]));

        $this->assertTrue(TruthSourceKeys::listed('1')->coversRow('1', []));
        $this->assertTrue(TruthSourceKeys::listed('1')->coversRow('1', [self::FOREIGN_SET]));
        $this->assertFalse(TruthSourceKeys::listed('1')->coversRow('2', [self::OWN_SET]));

        $set = TruthSourceKeys::set(self::OWN_SET);
        $this->assertTrue($set->coversRow('1', [self::OWN_SET]));
        $this->assertFalse($set->coversRow('1', [self::FOREIGN_SET]));
        $this->assertFalse($set->coversRow('1', [self::OWN_SET, self::FOREIGN_SET]));
        $this->assertFalse($set->coversRow('1', []));
    }

    public function testSetOwnerWritesARowOfItsSet(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', static fn(): array => [self::OWN_SET], TruthSourceOperation::Update);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', static fn(): array => [self::OWN_SET], TruthSourceOperation::Remove);

        $this->assertTrue(TruthSourceRegistry::hasTruthSource(self::COLLECTION));
    }

    public function testSetOwnerIsRefusedARowOfAnotherSetNamingBothKeys(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage(
            "Write operation not allowed: agent '" . self::AGENT_A . "' is not a truth source for table '"
            . self::COLLECTION . "' item '2': it holds set '42', and the item's set keys are [7]."
        );
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '2', static fn(): array => [self::FOREIGN_SET], TruthSourceOperation::Update);
    }

    public function testSetOwnerIsRefusedAMoveBetweenSets(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the item's set keys are [42, 7].");
        TruthSourceRegistry::checkCanWriteItem(
            self::COLLECTION,
            '1',
            static fn(): array => [self::OWN_SET, self::FOREIGN_SET],
            TruthSourceOperation::Update,
        );
    }

    public function testSetOwnerIsRefusedARowInNobodysSet(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the item's set keys are [].");
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', static fn(): array => [], TruthSourceOperation::Update);
    }

    public function testSetOwnerIsJudgedOnTheOperationAxisAfterBelonging(): void
    {
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::set(self::OWN_SET),
            self::AGENT_A,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', static fn(): array => [self::OWN_SET], TruthSourceOperation::Update);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage('may not remove item');
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', static fn(): array => [self::OWN_SET], TruthSourceOperation::Remove);
    }

    public function testSetClaimWithAddDoesNotMintRows(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(CreateNotAllowedException::class);
        TruthSourceRegistry::checkCanCreate(self::COLLECTION);
    }

    public function testSetClaimNamesNoKeysToReaders(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);

        $this->assertNull(TruthSourceRegistry::getTruthSourceKeys(self::COLLECTION));
        $this->assertFalse(TruthSourceRegistry::isTruthSource(self::COLLECTION, ['1']));
    }

    public function testAgentlessPathCoversARowOfAHeldSetAndNotOfAnother(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::set('9'),
            self::AGENT_B,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );

        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', static fn(): array => [self::OWN_SET], TruthSourceOperation::Remove);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '3', static fn(): array => ['9'], TruthSourceOperation::Update);

        try {
            TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '3', static fn(): array => ['9'], TruthSourceOperation::Remove);
            $this->fail('Expected the operations of the covering set claim alone to be asked');
        } catch (WriteNotAllowedException $e) {
            $this->assertStringContainsString('has operations [update] and may not remove item', $e->getMessage());
        }

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("no truth source covers table '" . self::COLLECTION . "' item '2'.");
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '2', static fn(): array => [self::FOREIGN_SET], TruthSourceOperation::Update);
    }

    /**
     * The set keys may read a parent row, so the claims that look at no set key never ask for them.
     */
    public function testTheWholeTableAndNamedRowsWriteWithoutAskingTheSetKeys(): void
    {
        $this->expectNotToPerformAssertions();

        $trap = static fn(): array => throw new LogicException('the set keys were asked of a claim that reads none');
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::all(), self::AGENT_A);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('2'), self::AGENT_B);

        ExecutionContext::setCurrentAgentId(self::AGENT_A);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', $trap, TruthSourceOperation::Update);

        ExecutionContext::setCurrentAgentId(self::AGENT_B);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '2', $trap, TruthSourceOperation::Update);

        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', $trap, TruthSourceOperation::Update);
    }

    public function testTheAgentlessPathAsksTheSetKeysOnceAcrossEveryClaimOverASet(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set('9'), self::AGENT_A);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_B);
        $calls = 0;
        $setKeys = static function () use (&$calls): array {
            $calls++;

            return [self::OWN_SET];
        };

        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', $setKeys, TruthSourceOperation::Update);

        $this->assertSame(1, $calls);
    }

    public function testASetOwnerRefusedNamesTheSetKeysAskedOnce(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);
        $calls = 0;
        $setKeys = static function () use (&$calls): array {
            $calls++;

            return [self::FOREIGN_SET];
        };

        try {
            TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '2', $setKeys, TruthSourceOperation::Update);
            $this->fail('Expected a row of another set to be refused');
        } catch (WriteNotAllowedException $e) {
            $this->assertStringContainsString("and the item's set keys are [7].", $e->getMessage());
        }

        $this->assertSame(1, $calls);
    }

    public function testCoversEveryRowOfSetAnswersEachWidthByItsOwnQuestion(): void
    {
        $this->assertTrue(TruthSourceKeys::all()->coversEveryRowOfSet(self::OWN_SET));
        $this->assertTrue(TruthSourceKeys::all()->coversEveryRowOfSet(self::FOREIGN_SET));
        $this->assertTrue(TruthSourceKeys::all()->coversEveryRowOfSet(''));

        $this->assertFalse(TruthSourceKeys::listed('1')->coversEveryRowOfSet(self::OWN_SET));
        $this->assertFalse(TruthSourceKeys::listed()->coversEveryRowOfSet(self::OWN_SET));
        $this->assertFalse(TruthSourceKeys::listed()->coversEveryRowOfSet(''));

        $set = TruthSourceKeys::set(self::OWN_SET);
        $this->assertTrue($set->coversEveryRowOfSet(self::OWN_SET));
        $this->assertFalse($set->coversEveryRowOfSet(self::FOREIGN_SET));
        $this->assertFalse($set->coversEveryRowOfSet(''));
    }

    public function testSetOwnerWritesItsSetInOneStatement(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWriteSet(self::COLLECTION, self::OWN_SET, static fn(): array => [self::OWN_SET], TruthSourceOperation::Update);
        TruthSourceRegistry::checkCanWriteSet(self::COLLECTION, self::OWN_SET, static fn(): array => [self::OWN_SET], TruthSourceOperation::Remove);

        $this->assertTrue(TruthSourceRegistry::hasTruthSource(self::COLLECTION));
    }

    public function testSetOwnerIsRefusedAStatementOverAnotherSetNamingBothKeys(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage(
            "Write operation not allowed: agent '" . self::AGENT_A . "' is not a truth source for table '"
            . self::COLLECTION . "' set '7': it holds set '42'."
        );
        TruthSourceRegistry::checkCanWriteSet(
            self::COLLECTION,
            self::FOREIGN_SET,
            static fn(): array => [self::FOREIGN_SET],
            TruthSourceOperation::Update,
        );
    }

    /**
     * The pack across the border the leaf was accepted on: owning one set is not owning the table.
     */
    public function testSetOwnerIsStillRefusedAStatementAcrossTheWholeTable(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("is not a collection-wide truth source for table '" . self::COLLECTION . "'.");
        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Update);
    }

    public function testNamedRowsCoverNoStatementOverASet(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('1', '2'), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage(
            "Write operation not allowed: agent '" . self::AGENT_A . "' is not a truth source for table '"
            . self::COLLECTION . "' set '42'."
        );
        TruthSourceRegistry::checkCanWriteSet(self::COLLECTION, self::OWN_SET, static fn(): array => [self::OWN_SET], TruthSourceOperation::Update);
    }

    public function testTheWholeTableCoversAStatementOverAnySet(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::all(), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWriteSet(self::COLLECTION, self::OWN_SET, static fn(): array => [self::OWN_SET], TruthSourceOperation::Remove);
        TruthSourceRegistry::checkCanWriteSet(
            self::COLLECTION,
            self::FOREIGN_SET,
            static fn(): array => [self::FOREIGN_SET],
            TruthSourceOperation::Remove,
        );
        TruthSourceRegistry::checkCanWriteSet(self::COLLECTION, '', static fn(): array => [''], TruthSourceOperation::Remove);

        $this->assertTrue(TruthSourceRegistry::hasTruthSource(self::COLLECTION));
    }

    public function testSetStatementIsJudgedOnTheOperationAxisAfterBelonging(): void
    {
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::set(self::OWN_SET),
            self::AGENT_A,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWriteSet(self::COLLECTION, self::OWN_SET, static fn(): array => [self::OWN_SET], TruthSourceOperation::Update);

        try {
            TruthSourceRegistry::checkCanWriteSet(
                self::COLLECTION,
                self::FOREIGN_SET,
                static fn(): array => [self::FOREIGN_SET],
                TruthSourceOperation::Remove,
            );
            $this->fail('Expected belonging to be asked before the operation');
        } catch (WriteNotAllowedException $e) {
            $this->assertStringContainsString("set '7': it holds set '42'.", $e->getMessage());
        }

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("with operations [update] and may not remove rows of set '42'.");
        TruthSourceRegistry::checkCanWriteSet(self::COLLECTION, self::OWN_SET, static fn(): array => [self::OWN_SET], TruthSourceOperation::Remove);
    }

    public function testAgentlessPathCoversAStatementOverAHeldSetAndNotOverAnother(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::set('9'),
            self::AGENT_B,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );

        TruthSourceRegistry::checkCanWriteSet(self::COLLECTION, self::OWN_SET, static fn(): array => [self::OWN_SET], TruthSourceOperation::Remove);
        TruthSourceRegistry::checkCanWriteSet(self::COLLECTION, '9', static fn(): array => ['9'], TruthSourceOperation::Update);

        try {
            TruthSourceRegistry::checkCanWriteSet(self::COLLECTION, '9', static fn(): array => ['9'], TruthSourceOperation::Remove);
            $this->fail('Expected the operations of the covering set claim alone to be asked');
        } catch (WriteNotAllowedException $e) {
            $this->assertStringContainsString("has operations [update] and may not remove rows of set '9'.", $e->getMessage());
        }

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("no truth source covers table '" . self::COLLECTION . "' set '7'.");
        TruthSourceRegistry::checkCanWriteSet(
            self::COLLECTION,
            self::FOREIGN_SET,
            static fn(): array => [self::FOREIGN_SET],
            TruthSourceOperation::Update,
        );
    }

    public function testDoorLetsTheSetOwnerWriteARowOfItsSet(): void
    {
        $actions = $this->actionsFor(SetWidthObject::fromEntity(SetWidthEntity::stored(1, self::OWN_OWNER_ID)));
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $actions->writePublic();
        $actions->deletePublic();

        $this->assertTrue(TruthSourceRegistry::hasTruthSource(self::COLLECTION));
    }

    /**
     * A row born after the agent started is covered by its set claim without another word.
     *
     * The claim is laid the declared way, the way a node starts the agent, and the rows are built
     * only after it: row 99 did not exist when the seam was asked. The grant holds the key of the
     * set and not a list gathered at start, so the door asks the new row which set it is in and
     * lets the owner of that set write it - and still refuses a row of another set born the same way.
     */
    public function testARowBornAfterTheDeclaredStartIsCoveredByTheSetClaim(): void
    {
        $agent = new SetWidthDeclaredAgent();
        OwnershipDeclaration::claimAll($agent);
        ExecutionContext::setCurrentAgentId($agent->getId());

        $actions = $this->actionsFor(SetWidthObject::fromEntity(SetWidthEntity::stored(99, self::OWN_OWNER_ID)));
        $actions->writePublic();
        $actions->deletePublic();

        $foreign = $this->actionsFor(SetWidthObject::fromEntity(SetWidthEntity::stored(100, self::FOREIGN_OWNER_ID)));

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the item's set keys are [7].");
        $foreign->writePublic();
    }

    public function testDoorRefusesTheSetOwnerARowOfAnotherSet(): void
    {
        $actions = $this->actionsFor(SetWidthObject::fromEntity(SetWidthEntity::stored(2, self::FOREIGN_OWNER_ID)));
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the item's set keys are [7].");
        $actions->writePublic();
    }

    public function testDoorRefusesTheSetOwnerAnUnsavedMoveOutOfItsSet(): void
    {
        $object = SetWidthObject::fromEntity(SetWidthEntity::stored(1, self::OWN_OWNER_ID));
        $object->moveTo(self::FOREIGN_OWNER_ID);
        $actions = $this->actionsFor($object);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the item's set keys are [42, 7].");
        $actions->writePublic();
    }

    public function testTouchedSetKeysNameTheStoredAndTheEditedSetEachOnce(): void
    {
        $object = SetWidthObject::fromEntity(SetWidthEntity::stored(1, self::OWN_OWNER_ID));
        $this->assertSame([self::OWN_SET], $object->touchedSetKeys());

        $object->moveTo(self::FOREIGN_OWNER_ID);
        $this->assertSame([self::OWN_SET, self::FOREIGN_SET], $object->touchedSetKeys());

        $object->moveTo(null);
        $this->assertSame([], $object->touchedSetKeys());

        $this->assertSame([], SetWidthObject::fromEntity(SetWidthEntity::stored(3, null))->touchedSetKeys());
    }

    public function testTouchedSetKeysAreEmptyForATableCutByNoSetColumn(): void
    {
        $standalone = new SetWidthStandaloneEntity();
        $standalone->id = 1;
        $standalone->flushRelated();
        $undeclared = new SetWidthUndeclaredEntity();
        $undeclared->id = 1;
        $undeclared->flushRelated();

        $this->assertSame([], SetWidthStandaloneObject::fromEntity($standalone)->touchedSetKeys());
        $this->assertSame([], SetWidthUndeclaredObject::fromEntity($undeclared)->touchedSetKeys());
    }

    /**
     * Builds item actions on top of a fixture object and collection.
     *
     * @param SetWidthObject $object Stored row the door is asked to write
     * @return SetWidthDbActions The item actions door, ready to be tested
     */
    private function actionsFor(SetWidthObject $object): SetWidthDbActions
    {
        $objectCollection = SetWidthObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $dbCollection = SetWidthDbCollection::init();
        $dbCollection->setObjectCollection($objectCollection);
        $dbCollection->setItemActionsClass(SetWidthDbActions::class);

        $item = $dbCollection->createItemPublic($object);

        /** @var SetWidthDbActions */
        return $item->actions;
    }
}

/**
 * A table cut into sets by the column naming the row's owner.
 */
final class SetWidthEntity extends Entity
{
    public const string id = 'id';
    public const string owner_id = 'owner_id';

    public const string _table = 'set_width_test';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::owner_id];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::owner_id => PhpType::INTEGER->value,
    ];

    public const string _setVia = self::owner_id;
    public const bool _setRoot = false;

    public ?int $id = null;
    public ?int $owner_id = null;

    /**
     * @param int $id Row id
     * @param ?int $ownerId Set column value the row is stored under, null for a row in no set
     * @return self A row as it stands in the database
     */
    public static function stored(int $id, ?int $ownerId): self
    {
        $entity = new self();
        $entity->id = $id;
        $entity->owner_id = $ownerId;
        $entity->flushRelated();

        return $entity;
    }
}

/**
 * Object fixture over the set-cut table, able to move its row to another set without saving.
 */
final class SetWidthObject extends Object_
{
    public const string ENTITY_CLASS = SetWidthEntity::class;

    /**
     * Edits the set column in memory only, as an unsaved edit would.
     *
     * @param ?int $ownerId Set column value the edit moves the row to
     */
    public function moveTo(?int $ownerId): void
    {
        $this->entity->owner_id = $ownerId;
    }
}

/**
 * A table whose rows belong to nobody's set.
 */
final class SetWidthStandaloneEntity extends Entity
{
    public const string id = 'id';

    public const string _table = 'set_width_standalone_test';
    public const string _primary = self::id;
    public const array _columns = [self::id];
    public const array _types = [self::id => PhpType::INTEGER->value];

    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    public ?int $id = null;
}

/**
 * Object fixture over the standalone table.
 */
final class SetWidthStandaloneObject extends Object_
{
    public const string ENTITY_CLASS = SetWidthStandaloneEntity::class;
}

/**
 * A table that declares no set column at all.
 */
final class SetWidthUndeclaredEntity extends Entity
{
    public const string id = 'id';

    public const string _table = 'set_width_undeclared_test';
    public const string _primary = self::id;
    public const array _columns = [self::id];
    public const array _types = [self::id => PhpType::INTEGER->value];

    public ?int $id = null;
}

/**
 * Object fixture over the table that declares no set column.
 */
final class SetWidthUndeclaredObject extends Object_
{
    public const string ENTITY_CLASS = SetWidthUndeclaredEntity::class;
}

/**
 * Objects collection fixture declaring item and collection classes.
 */
final class SetWidthObjects extends Objects
{
    public const string OBJECT_CLASS = SetWidthObject::class;
    public const string COLLECTION_KEY = 'unit_set_width';
    public const string DB_ITEM_CLASS = SetWidthDbItem::class;
    public const string OBJECT_COLLECTION_CLASS = self::class;
}

/**
 * Minimal DbItem fixture for the set width door test.
 */
final class SetWidthDbItem extends DbItem
{
}

/**
 * DbCollection fixture opening createDbItem() to public for testing.
 */
final class SetWidthDbCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = SetWidthDbItem::class;
    public const string OBJECT_COLLECTION_CLASS = SetWidthObjects::class;

    /**
     * Creates DbItem for testing.
     *
     * @param Object_ $object Object instance to wrap
     * @return SetWidthDbItem Item view wrapping the object
     */
    public function createItemPublic(Object_ $object): SetWidthDbItem
    {
        /** @var SetWidthDbItem */
        return $this->createDbItem($object);
    }
}

/**
 * Exposes the two item write doors (default update and explicit remove) to public.
 */
final class SetWidthDbActions extends DbActions
{
    /**
     * Exposes ensureCanWrite() using the default operation (Update).
     *
     * @throws WriteNotAllowedException When the truth source rejects update
     */
    public function writePublic(): void
    {
        $this->ensureCanWrite();
    }

    /**
     * Exposes ensureCanWrite() using TruthSourceOperation::Remove.
     *
     * @throws WriteNotAllowedException When the truth source rejects remove
     */
    public function deletePublic(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
    }
}

/**
 * An agent declaring the set-cut table by a set it may edit and remove from but not add to.
 *
 * Borrowed, as the agent of one person will be: the rows of its set are brought into being by
 * somebody else, and it edits them.
 */
final class SetWidthDeclaredAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_set_width_declared';

    public const array OWNS_DB_SET = [SetWidthObjects::COLLECTION_KEY => [TruthSourceOperation::Update, TruthSourceOperation::Remove]];

    /**
     * @param string $collection Collection the resolver is asking about
     * @return string The one set this instance answers for
     */
    public function ownedDbSetKey(string $collection): string
    {
        return '42';
    }

    /**
     * Claims nothing here: the declaration above and the seam beside it are the claim.
     */
    public function onStart(): void
    {
    }

    /**
     * Holds nothing across a stop.
     */
    public function onStop(): void
    {
    }
}
