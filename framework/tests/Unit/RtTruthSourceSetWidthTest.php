<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Actions\Item\RtActions as RtItemActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * HIL-1115: the third width of a claim on the runtime half - the rows of one set - answered by the
 * field the row class names in {@see RtState::SET_VIA}.
 *
 * The mirror of {@see TruthSourceSetWidthTest}: an agent holding a set writes a row of its set and
 * is refused a row of another, a row in nobody's set and a move between sets, and every door of
 * the runtime write path hands the registry the keys the row itself counts.
 */
final class RtTruthSourceSetWidthTest extends TestCase
{
    private const string AGENT_A = 'unit_rt_set_agent:a';
    private const string AGENT_B = 'unit_rt_set_agent:b';
    private const string OWN_SET = '42';
    private const string FOREIGN_SET = '7';

    private ?RtContext $previousRuntime = null;

    private ?SignalRouter $previousSignalRouter = null;

    protected function setUp(): void
    {
        $this->previousRuntime = Hilos::$rt;
        $this->previousSignalRouter = Hilos::$sr;
        // Without a router RtState::sync() asks for no right at all, and the move it must catch
        // would pass unseen.
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT_A);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT_B);
        RtTruthSourceRegistry::unregisterAgent(SetWidthRtDeclaredAgent::AGENT_TYPE);
        // The declared claim is a reader interest too, so it is given back beside the claim itself.
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(SetWidthRtDeclaredAgent::AGENT_TYPE));
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    public function testSetOwnerWritesAStateOfItsSet(): void
    {
        $this->claimOwnSet(self::AGENT_A);

        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '1', [self::OWN_SET], TruthSourceOperation::Update);
        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '1', [self::OWN_SET], TruthSourceOperation::Remove);

        $this->assertTrue(RtTruthSourceRegistry::hasTruthSource(SetWidthRtContext::CUT));
    }

    public function testSetOwnerIsRefusedAStateOfAnotherSetNamingBothKeys(): void
    {
        $this->claimOwnSet(self::AGENT_A);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage(
            "Write operation not allowed: agent '" . self::AGENT_A . "' is not a truth source for runtime collection '"
            . SetWidthRtContext::CUT . "' state '2': it holds set '42', and the state's set keys are [7]."
        );
        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '2', [self::FOREIGN_SET], TruthSourceOperation::Update);
    }

    public function testSetOwnerIsRefusedAMoveBetweenSets(): void
    {
        $this->claimOwnSet(self::AGENT_A);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the state's set keys are [42, 7].");
        RtTruthSourceRegistry::checkCanWriteState(
            SetWidthRtContext::CUT,
            '1',
            [self::OWN_SET, self::FOREIGN_SET],
            TruthSourceOperation::Update,
        );
    }

    public function testSetOwnerIsRefusedAStateInNobodysSet(): void
    {
        $this->claimOwnSet(self::AGENT_A);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the state's set keys are [].");
        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '1', [], TruthSourceOperation::Update);
    }

    public function testSetOwnerIsJudgedOnTheOperationAxisAfterBelonging(): void
    {
        RtTruthSourceRegistry::register(
            SetWidthRtContext::CUT,
            TruthSourceKeys::set(self::OWN_SET),
            self::AGENT_A,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '1', [self::OWN_SET], TruthSourceOperation::Update);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage("with operations [update] and may not remove state '1'.");
        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '1', [self::OWN_SET], TruthSourceOperation::Remove);
    }

    /**
     * The two older widths answer by the row's key as before, whatever set the row stands in.
     */
    public function testTheWholeCollectionAndNamedRowsLookAtNoSetKey(): void
    {
        RtTruthSourceRegistry::register(SetWidthRtContext::CUT, TruthSourceKeys::all(), self::AGENT_A);
        RtTruthSourceRegistry::register(SetWidthRtContext::CUT, TruthSourceKeys::listed('2'), self::AGENT_B);

        ExecutionContext::setCurrentAgentId(self::AGENT_A);
        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '1', [self::FOREIGN_SET], TruthSourceOperation::Update);

        ExecutionContext::setCurrentAgentId(self::AGENT_B);
        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '2', [self::OWN_SET, self::FOREIGN_SET], TruthSourceOperation::Update);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage(
            "agent '" . self::AGENT_B . "' is not a truth source for runtime collection '" . SetWidthRtContext::CUT . "' state '1'."
        );
        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '1', [], TruthSourceOperation::Update);
    }

    public function testAgentlessPathCoversAStateOfAHeldSetAndNotOfAnother(): void
    {
        RtTruthSourceRegistry::register(SetWidthRtContext::CUT, TruthSourceKeys::set(self::OWN_SET), self::AGENT_A);
        RtTruthSourceRegistry::register(
            SetWidthRtContext::CUT,
            TruthSourceKeys::set('9'),
            self::AGENT_B,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );

        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '1', [self::OWN_SET], TruthSourceOperation::Remove);
        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '3', ['9'], TruthSourceOperation::Update);

        try {
            RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '3', ['9'], TruthSourceOperation::Remove);
            $this->fail('Expected the operations of the covering set claim alone to be asked');
        } catch (RtTruthSourceWriteNotAllowedException $e) {
            $this->assertStringContainsString('has operations [update] and may not remove state', $e->getMessage());
        }

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage("no truth source covers runtime collection '" . SetWidthRtContext::CUT . "' state '2'.");
        RtTruthSourceRegistry::checkCanWriteState(SetWidthRtContext::CUT, '2', [self::FOREIGN_SET], TruthSourceOperation::Update);
    }

    public function testTouchedSetKeysNameTheStoredAndTheEditedSetEachOnce(): void
    {
        $state = SetWidthRtState::stored('1', self::OWN_SET);
        $this->assertSame([self::OWN_SET], $state->touchedSetKeys());

        $state->ownerId = self::FOREIGN_SET;
        $this->assertSame([self::OWN_SET, self::FOREIGN_SET], $state->touchedSetKeys());

        $state->ownerId = null;
        $this->assertSame([], $state->touchedSetKeys());

        $this->assertSame([], SetWidthRtState::stored('2', null)->touchedSetKeys());
    }

    public function testTouchedSetKeysReadTheEditedSetOutOfAPendingDiff(): void
    {
        $state = SetWidthRtState::stored('1', self::OWN_SET);

        $this->assertSame([self::OWN_SET, self::FOREIGN_SET], $state->touchedSetKeys([SetWidthRtState::ownerId => self::FOREIGN_SET]));
        $this->assertSame([self::OWN_SET], $state->touchedSetKeys([SetWidthRtState::note => 'edited']));
        $this->assertSame([], $state->touchedSetKeys([SetWidthRtState::ownerId => null]));
    }

    /**
     * A row never synced has no stored copy of the field and writes into the set it is born in.
     */
    public function testTouchedSetKeysOfAnUnsyncedRowNameTheEditedSetAlone(): void
    {
        $state = SetWidthRtState::unsynced('1', self::FOREIGN_SET);

        $this->assertSame([self::FOREIGN_SET], $state->touchedSetKeys());
        $this->assertSame([self::OWN_SET], $state->touchedSetKeys([SetWidthRtState::ownerId => self::OWN_SET]));
    }

    public function testTouchedSetKeysAreEmptyForARowClassCutByNoField(): void
    {
        $this->assertSame([], SetWidthUncutRtState::stored('1', self::OWN_SET)->touchedSetKeys());
    }

    public function testAddDoorLetsTheSetOwnerBringARowOfItsSet(): void
    {
        $collection = $this->mounted();
        $this->claimOwnSet(self::AGENT_A);

        $collection->actions->put(SetWidthRtState::stored('1', self::OWN_SET));

        $this->assertNotNull($collection['1']);
    }

    public function testAddDoorRefusesTheSetOwnerARowOfAnotherSet(): void
    {
        $collection = $this->mounted();
        $this->claimOwnSet(self::AGENT_A);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the state's set keys are [7].");
        $collection->actions->put(SetWidthRtState::stored('2', self::FOREIGN_SET));
    }

    /**
     * A borrowed claim over a set edits the rows of its set and brings none of them into being.
     */
    public function testAddDoorRefusesASetClaimWithoutAddEvenARowOfItsSet(): void
    {
        $collection = $this->mounted();
        RtTruthSourceRegistry::register(
            SetWidthRtContext::CUT,
            TruthSourceKeys::set(self::OWN_SET),
            self::AGENT_A,
            TruthSourceOperations::of(TruthSourceOperation::Update, TruthSourceOperation::Remove),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage("with operations [update, remove] and may not add state '1'.");
        $collection->actions->put(SetWidthRtState::stored('1', self::OWN_SET));
    }

    public function testDiffDoorRefusesTheSetOwnerAMoveOutOfItsSet(): void
    {
        $collection = $this->mounted();
        $state = SetWidthRtState::stored('1', self::OWN_SET);
        $this->claimOwnSet(self::AGENT_A);
        $collection->actions->put($state);

        try {
            $collection->actions->patch($state, [SetWidthRtState::ownerId => self::FOREIGN_SET]);
            $this->fail('Expected a move to another set to be refused');
        } catch (RtTruthSourceWriteNotAllowedException $e) {
            $this->assertStringContainsString("it holds set '42', and the state's set keys are [42, 7].", $e->getMessage());
        }

        $this->assertSame(self::OWN_SET, $state->ownerId);
    }

    public function testDiffDoorLetsTheSetOwnerEditARowOfItsSet(): void
    {
        $collection = $this->mounted();
        $state = SetWidthRtState::stored('1', self::OWN_SET);
        $this->claimOwnSet(self::AGENT_A);
        $collection->actions->put($state);

        $collection->actions->patch($state, [SetWidthRtState::note => 'edited']);

        $this->assertSame('edited', $state->note);
    }

    public function testRemoveDoorRefusesTheSetOwnerARowOfAnotherSet(): void
    {
        $collection = $this->mounted();
        RtTruthSourceRegistry::register(SetWidthRtContext::CUT, TruthSourceKeys::all(), self::AGENT_B);
        ExecutionContext::setCurrentAgentId(self::AGENT_B);
        $collection->actions->put(SetWidthRtState::stored('2', self::FOREIGN_SET));
        $this->claimOwnSet(self::AGENT_A);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the state's set keys are [7].");
        $collection->actions->drop('2');
    }

    /**
     * The item door is asked before the fields are assigned, so the move is not in the row there;
     * the sync that follows the assignment asks again and refuses it.
     */
    public function testItemDoorLetsTheMoveThroughAndItsSyncRefusesIt(): void
    {
        $collection = $this->mounted();
        $this->claimOwnSet(self::AGENT_A);
        $collection->actions->put(SetWidthRtState::stored('1', self::OWN_SET));

        $item = $collection['1'];
        $this->assertNotNull($item);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the state's set keys are [42, 7].");
        $item->actions->moveTo(self::FOREIGN_SET);
    }

    /**
     * A row of the set brought into being after the declared start is covered without another word.
     *
     * The claim is laid the declared way, the way a node starts the agent, and the rows are built
     * only after it. The grant holds the key of the set and not a list gathered at start, so the
     * door asks the new row which set it is in - and still refuses a row of another set born the
     * same way.
     */
    public function testARowBornAfterTheDeclaredStartIsCoveredByTheSetClaim(): void
    {
        $collection = $this->mounted();
        $agent = new SetWidthRtDeclaredAgent();
        OwnershipDeclaration::claimAll($agent);
        ExecutionContext::setCurrentAgentId($agent->getId());

        $state = SetWidthRtState::stored('99', self::OWN_SET);
        $collection->actions->put($state);
        $collection->actions->patch($state, [SetWidthRtState::note => 'edited']);
        $collection->actions->drop('99');

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42', and the state's set keys are [7].");
        $collection->actions->put(SetWidthRtState::stored('100', self::FOREIGN_SET));
    }

    /**
     * Lays a claim over the one set these cases own and makes its holder the current writer.
     *
     * @param string $agentId Agent that holds the claim
     */
    private function claimOwnSet(string $agentId): void
    {
        RtTruthSourceRegistry::register(SetWidthRtContext::CUT, TruthSourceKeys::set(self::OWN_SET), $agentId);
        ExecutionContext::setCurrentAgentId($agentId);
    }

    /**
     * Mounts the fixture context as the runtime of this process.
     *
     * @return SetWidthRtCollection Mounted view collection of the set-cut rows
     * @throws StateCollectionNotFoundException When the state collection was not registered first
     * @throws RtCollectionNotFoundException When configure() did not mount the view
     */
    private function mounted(): SetWidthRtCollection
    {
        $context = new SetWidthRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$rt = $context;

        return $context->collection();
    }
}

/**
 * Runtime context mounting the one collection cut by a field these cases write.
 */
final class SetWidthRtContext extends RtContext
{
    public const string CUT = 'unit_rt_set_width';

    /**
     * Registers the state collection and its views, as a project context does.
     *
     * @throws StateCollectionNotFoundException When the state collection was not registered first
     */
    public function configure(): void
    {
        $this->_stateCollections[self::CUT] = SetWidthRtStates::init();
        $this->setRepresent(self::CUT, SetWidthRtCollection::class, SetWidthRtActions::class, SetWidthRtItemActions::class);
    }

    /**
     * Hands back the mounted view of the set-cut rows without going through the magic getter.
     *
     * @return SetWidthRtCollection Mounted view collection
     * @throws RtCollectionNotFoundException When configure() has not run
     */
    public function collection(): SetWidthRtCollection
    {
        $collection = $this->getRtCollection(self::CUT);

        return $collection instanceof SetWidthRtCollection
            ? $collection
            : throw new RtCollectionNotFoundException('Set-width fixture collection is not mounted');
    }
}

/**
 * Runtime row cut into sets by the owner it carries.
 */
final class SetWidthRtState extends RtState
{
    public const string id = 'id';
    public const string ownerId = 'ownerId';
    public const string note = 'note';

    public const string SET_VIA = self::ownerId;

    public private(set) string $id = '';

    public ?string $ownerId = null;

    public string $note = '';

    /**
     * @param string $id Row key
     * @param ?string $ownerId Owner whose set the row is in, null for nobody's
     * @return self A row as it stands after its last sync
     */
    public static function stored(string $id, ?string $ownerId): self
    {
        $state = self::unsynced($id, $ownerId);
        $state->markRtSyncBaseline();

        return $state;
    }

    /**
     * @param string $id Row key
     * @param ?string $ownerId Owner whose set the row is in, null for nobody's
     * @return self A row that was never synced, with no stored copy of any field
     */
    public static function unsynced(string $id, ?string $ownerId): self
    {
        $state = new self();
        $state->id = $id;
        $state->ownerId = $ownerId;

        return $state;
    }

    public static function getRtCollectionKey(): string
    {
        return SetWidthRtContext::CUT;
    }

    public static function fromRow(array $row): static
    {
        $state = self::stored(self::requireString($row, self::id), self::optionalString($row, self::ownerId));
        $state->note = self::requireString($row, self::note);

        return $state;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function applyDiff(array $diff): void
    {
        $this->ownerId = self::patchOptionalString($diff, self::ownerId, $this->ownerId);
        $this->note = self::patchString($diff, self::note, $this->note);
    }

    public function toArray(): array
    {
        return [self::id => $this->id, self::ownerId => $this->ownerId, self::note => $this->note];
    }
}

/**
 * Runtime row carrying an owner too, whose class names no field to cut by.
 */
final class SetWidthUncutRtState extends RtState
{
    public const string id = 'id';
    public const string ownerId = 'ownerId';

    public private(set) string $id = '';

    public ?string $ownerId = null;

    /**
     * @param string $id Row key
     * @param ?string $ownerId Owner the row names, which cuts nothing here
     * @return self A row as it stands after its last sync
     */
    public static function stored(string $id, ?string $ownerId): self
    {
        $state = new self();
        $state->id = $id;
        $state->ownerId = $ownerId;
        $state->markRtSyncBaseline();

        return $state;
    }

    public static function fromRow(array $row): static
    {
        return self::stored(self::requireString($row, self::id), self::optionalString($row, self::ownerId));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function toArray(): array
    {
        return [self::id => $this->id, self::ownerId => $this->ownerId];
    }
}

/**
 * @extends RtStates<SetWidthRtState>
 */
final class SetWidthRtStates extends RtStates
{
    public const string STATE_CLASS = SetWidthRtState::class;
}

/**
 * @extends RtItem<SetWidthRtState>
 */
final class SetWidthRtItem extends RtItem
{
    /**
     * @throws HilosException When the item actions class is missing or the property is not declared
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

/**
 * @extends RtCollection<SetWidthRtItem, SetWidthRtActions>
 */
final class SetWidthRtCollection extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new SetWidthRtItem($state);
    }
}

/**
 * Opens the protected doors of the base collection actions.
 *
 * @extends RtActions<SetWidthRtItem, SetWidthRtCollection, SetWidthRtStates>
 */
final class SetWidthRtActions extends RtActions
{
    /**
     * @param RtState $state Row to bring into the collection
     * @throws HilosException On a truth-source refusal or a failing announcement
     */
    public function put(RtState $state): void
    {
        $this->addStateToCollection($state);
    }

    /**
     * @param RtState $state Row to edit
     * @param array<string, mixed> $diff Fields to apply
     * @throws HilosException On a truth-source refusal or a failing sync
     */
    public function patch(RtState $state, array $diff): void
    {
        $this->applyDiffToState($state, $diff);
    }

    /**
     * @param string $id Key of the row to remove
     * @throws HilosException On a truth-source refusal or a failing announcement
     */
    public function drop(string $id): void
    {
        $this->removeStateFromCollection($id);
    }
}

/**
 * Moves one row between sets the way a project's item action writes: door first, then assign, then sync.
 *
 * @extends RtItemActions<SetWidthRtItem, SetWidthRtState>
 * @property-read SetWidthRtState $state
 */
final class SetWidthRtItemActions extends RtItemActions
{
    /**
     * @param string $ownerId Owner of the set to move the row into
     * @throws HilosException On a truth-source refusal or a failing sync
     */
    public function moveTo(string $ownerId): void
    {
        $this->ensureCanWrite();

        $this->state->ownerId = $ownerId;

        $this->sync();
    }
}

/**
 * An agent declaring the set-cut runtime collection by the set of one owner.
 */
final class SetWidthRtDeclaredAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_rt_set_width_declared';

    public const array OWNS_RT_SET = [SetWidthRtContext::CUT => TruthSourceOperation::BY_KIND];

    /**
     * @param string $collection Collection the resolver is asking about
     * @return string The one set this instance answers for
     */
    public function ownedRtSetKey(string $collection): string
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
