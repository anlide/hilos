<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Hilos;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosProfileNameChanges as StateHilosProfileNameChanges;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Runtime\View\Actions\Collection\HilosProfileNameChangesActions;
use Hilos\Runtime\View\Actions\Item\HilosProfileNameChangeActions;
use Hilos\Runtime\View\Collection\HilosProfileNameChanges;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosProfileNameChange;
use Hilos\TruthSource\RtTruthSourceRegistry;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pins storage and ownership without enabling the session name-change protocol. */
final class HilosProfileNameChangesTest extends TestCase
{
    private const string SESSION_HASH = 'session-one-hash';
    private const string OTHER_SESSION_HASH = 'session-two-hash';
    private const string REQUEST_ID = '0123456789abcdef0123456789abcdef';
    private const string NEXT_REQUEST_ID = 'fedcba9876543210fedcba9876543210';
    private const string ACCEPT_KEY = 'origin-connection';
    private const string NAME = 'Alexandra';
    private const string REFUSAL = 'This name was not accepted.';
    private const int USER_ID = 1635;
    private const int STARTED_AT = 1_900_000_000_000;
    private const int DEADLINE_AT = 1_900_000_030_000;

    private ?SignalRouter $previousSignalRouter = null;
    private ?RtContext $previousRuntime = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRuntime = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new EmptyTestRtContext();
        Hilos::$rt->mountFeatureRuntime([new AuthFeature()]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(StateHilosProfileNameChange::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosProfileNameChange::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    public function testAllFieldsSurviveTheSyncBoundary(): void
    {
        $attempt = $this->newState();
        self::assertSame(self::row(), $attempt->toArray());
        self::assertSame(self::SESSION_HASH, $attempt->getId());
        self::assertSame(StateHilosProfileNameChange::RT_COLLECTION, $attempt::getRtCollectionKey());

        $row = self::row();
        $row[StateHilosProfileNameChange::phase] = StateHilosProfileNameChange::PHASE_REFUSED;
        $row[StateHilosProfileNameChange::refusal] = self::REFUSAL;
        $row[StateHilosProfileNameChange::deadlineAt] = null;
        self::assertSame($row, StateHilosProfileNameChange::fromRow($row)->toArray());
        self::assertSame(self::row(), StateHilosProfileNameChange::fromRow(self::row())->toArray());
    }

    public function testADiffPatchesEveryNonAddressField(): void
    {
        $attempt = $this->newState();
        $diff = [
            StateHilosProfileNameChange::sessionTokenHash => self::OTHER_SESSION_HASH,
            StateHilosProfileNameChange::userId => self::USER_ID + 1,
            StateHilosProfileNameChange::requestId => self::NEXT_REQUEST_ID,
            StateHilosProfileNameChange::originAcceptKey => 'another-connection',
            StateHilosProfileNameChange::name => 'Another name',
            StateHilosProfileNameChange::phase => StateHilosProfileNameChange::PHASE_REFUSED,
            StateHilosProfileNameChange::refusal => self::REFUSAL,
            StateHilosProfileNameChange::startedAt => self::STARTED_AT + 1,
            StateHilosProfileNameChange::deadlineAt => self::DEADLINE_AT + 1,
        ];
        $attempt->applyDiff($diff);
        $diff[StateHilosProfileNameChange::sessionTokenHash] = self::SESSION_HASH;
        self::assertSame($diff, $attempt->toArray());
        self::assertSame(self::SESSION_HASH, $attempt->getId());

        $attempt->applyDiff([StateHilosProfileNameChange::phase => StateHilosProfileNameChange::PHASE_DONE]);
        self::assertSame(self::REFUSAL, $attempt->refusal);
        self::assertSame(self::DEADLINE_AT + 1, $attempt->deadlineAt);
        $attempt->applyDiff([
            StateHilosProfileNameChange::refusal => null,
            StateHilosProfileNameChange::deadlineAt => null,
        ]);
        $terminal = $attempt->toArray();
        $attempt->applyDiff([]);
        self::assertSame($terminal, $attempt->toArray());
        self::assertNull($attempt->refusal);
        self::assertNull($attempt->deadlineAt);
    }

    public function testAMissingRequiredFieldIsRefused(): void
    {
        $row = self::row();
        unset($row[StateHilosProfileNameChange::requestId]);
        $this->expectException(InvalidFormatException::class);

        StateHilosProfileNameChange::fromRow($row);
    }

    public function testAStringMomentIsRefused(): void
    {
        $row = self::row();
        $row[StateHilosProfileNameChange::deadlineAt] = (string)self::DEADLINE_AT;
        $this->expectException(InvalidFormatException::class);

        StateHilosProfileNameChange::fromRow($row);
    }

    public function testAWrongDiffTypeIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->newState()->applyDiff([StateHilosProfileNameChange::refusal => false]);
    }

    public function testTheStateCollectionHasTypedSessionKeyAccess(): void
    {
        $states = StateHilosProfileNameChanges::init();
        $attempt = $this->newState();
        // This store is detached; mounted membership is exercised through actions below.
        $states->add($attempt);

        self::assertSame($attempt, $states[self::SESSION_HASH]);
        self::assertSame($attempt, $states->get(self::SESSION_HASH));
        self::assertNull($states->get(null));
        self::assertNull($states->get(self::OTHER_SESSION_HASH));
    }

    public function testRequiredStateAccessRefusesAMissingKey(): void
    {
        $this->expectException(OutOfBoundsException::class);

        StateHilosProfileNameChanges::init()[null];
    }

    public function testTheAuthMountExposesTypedRowsAndBothActionLayers(): void
    {
        $this->open();

        self::assertInstanceOf(HilosProfileNameChanges::class, Hilos::$rt->hilosProfileNameChanges);
        self::assertInstanceOf(HilosProfileNameChangesActions::class, Hilos::$rt->hilosProfileNameChanges->actions);
        self::assertInstanceOf(HilosProfileNameChange::class, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]);
        self::assertInstanceOf(
            HilosProfileNameChangeActions::class,
            Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions,
        );
        self::assertSame(self::row(), Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->toArray());
        self::assertSame(self::SESSION_HASH, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->sessionTokenHash);
        self::assertSame(self::USER_ID, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->userId);
        self::assertSame(self::REQUEST_ID, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->requestId);
        self::assertSame(self::ACCEPT_KEY, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->originAcceptKey);
        self::assertSame(self::NAME, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->name);
        self::assertSame(StateHilosProfileNameChange::PHASE_CHECKING, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->phase);
        self::assertNull(Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->refusal);
        self::assertSame(self::STARTED_AT, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->startedAt);
        self::assertSame(self::DEADLINE_AT, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->deadlineAt);
        self::assertNull(Hilos::$rt->hilosProfileNameChanges[null]);
        self::assertNull(Hilos::$rt->hilosProfileNameChanges[self::OTHER_SESSION_HASH]);
    }

    public function testApplyingAndDoneRetainTheAttemptWithoutTheCheckingDeadline(): void
    {
        $this->open();
        Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->markApplying();
        self::assertSame(StateHilosProfileNameChange::PHASE_APPLYING, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->phase);
        self::assertNull(Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->deadlineAt);
        self::assertNull(Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->refusal);

        Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->complete();
        self::assertSame(StateHilosProfileNameChange::PHASE_DONE, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->phase);
        self::assertNull(Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->deadlineAt);
        self::assertNull(Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->refusal);
        self::assertSame(self::REQUEST_ID, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->requestId);
        self::assertCount(1, Hilos::$rt->hilosProfileNameChanges);
    }

    public function testRefusalRetainsTheSentenceAndRemovesTheDeadline(): void
    {
        $this->open();
        Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->refuse(self::REFUSAL);

        self::assertSame(StateHilosProfileNameChange::PHASE_REFUSED, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->phase);
        self::assertSame(self::REFUSAL, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->refusal);
        self::assertNull(Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->deadlineAt);
        self::assertSame(self::NAME, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->name);
        self::assertSame(self::STARTED_AT, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->startedAt);
        self::assertCount(1, Hilos::$rt->hilosProfileNameChanges);
    }

    public function testANewAttemptRemovesTheOldRowBeforeAddingTheSameId(): void
    {
        $this->open();
        Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->refuse(self::REFUSAL);
        $previous = Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH];
        $recorder = new ProfileNameChangeRecorder();
        SourceChangeBus::subscribe($recorder);

        Hilos::$rt->hilosProfileNameChanges->actions->open(
            self::SESSION_HASH, self::USER_ID + 1, self::NEXT_REQUEST_ID, 'new-origin', ' New name ',
            self::STARTED_AT + 1, self::DEADLINE_AT + 1,
        );

        self::assertSame([TableMutationType::Delete, TableMutationType::Create], $recorder->mutations);
        self::assertCount(1, Hilos::$rt->hilosProfileNameChanges);
        self::assertNotSame($previous, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]);
        self::assertSame([
            StateHilosProfileNameChange::sessionTokenHash => self::SESSION_HASH,
            StateHilosProfileNameChange::userId => self::USER_ID + 1,
            StateHilosProfileNameChange::requestId => self::NEXT_REQUEST_ID,
            StateHilosProfileNameChange::originAcceptKey => 'new-origin',
            StateHilosProfileNameChange::name => 'New name',
            StateHilosProfileNameChange::phase => StateHilosProfileNameChange::PHASE_CHECKING,
            StateHilosProfileNameChange::refusal => null,
            StateHilosProfileNameChange::startedAt => self::STARTED_AT + 1,
            StateHilosProfileNameChange::deadlineAt => self::DEADLINE_AT + 1,
        ], Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->toArray());
    }

    public function testCleanupRemovesOnlyTheAddressedSession(): void
    {
        $this->open();
        $this->open(self::OTHER_SESSION_HASH);
        Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->drop();

        self::assertNull(Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]);
        self::assertSame(self::REQUEST_ID, Hilos::$rt->hilosProfileNameChanges[self::OTHER_SESSION_HASH]->requestId);
        self::assertCount(1, Hilos::$rt->hilosProfileNameChanges);
        Hilos::$rt->hilosProfileNameChanges[self::OTHER_SESSION_HASH]->actions->drop();
        self::assertCount(0, Hilos::$rt->hilosProfileNameChanges);
    }

    /** @return iterable<string, array{bool, bool}> AUTH and moderation activation combinations */
    public static function activations(): iterable
    {
        yield 'neither feature' => [false, false];
        yield 'AUTH alone' => [true, false];
        yield 'moderation alone' => [false, true];
        yield 'AUTH and moderation' => [true, true];
    }

    /**
     * @param bool $auth Whether AUTH is declared
     * @param bool $moderation Whether moderation is declared
     */
    #[DataProvider('activations')]
    public function testOnlyAuthMountsNameChanges(bool $auth, bool $moderation): void
    {
        $definitions = [];
        if ($auth) {
            $definitions[] = new AuthFeature();
        }
        if ($moderation) {
            $definitions[] = new ModerationFeature();
        }
        $context = new EmptyTestRtContext();
        $context->mountFeatureRuntime($definitions);
        $context->configure();
        $context->assertFeatureRuntimeIntact();

        self::assertSame($auth, $context->hasSource(StateHilosProfileNameChange::RT_COLLECTION));
        self::assertArrayNotHasKey(
            StateHilosProfileNameChange::RT_COLLECTION,
            OwnershipDeclaration::rtCollectionsOf(AbstractSessionsLibraryAgent::class),
        );
    }

    /** @return iterable<string, array{string}> Mutation APIs that require the session owner */
    public static function itemWrites(): iterable
    {
        yield 'applying' => ['markApplying'];
        yield 'refusal' => ['refuse'];
        yield 'completion' => ['complete'];
        yield 'cleanup' => ['drop'];
    }

    /** @param string $method Item action attempted without ownership */
    #[DataProvider('itemWrites')]
    public function testItemWritesRefuseANonOwner(string $method): void
    {
        $this->open();
        RtTruthSourceRegistry::unregisterDaemon(StateHilosProfileNameChange::RT_COLLECTION);
        $this->expectException(RtTruthSourceWriteNotAllowedException::class);

        if ($method === 'refuse') {
            Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->refuse(self::REFUSAL);
        } else {
            Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->$method();
        }
    }

    public function testOpeningRefusesANonOwner(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosProfileNameChange::RT_COLLECTION);
        $this->expectException(RtTruthSourceWriteNotAllowedException::class);

        $this->open();
    }

    /** @param string $sessionTokenHash Session to open the attempt for */
    private function open(string $sessionTokenHash = self::SESSION_HASH): void
    {
        Hilos::$rt->hilosProfileNameChanges->actions->open(
            $sessionTokenHash, self::USER_ID, self::REQUEST_ID, self::ACCEPT_KEY, self::NAME,
            self::STARTED_AT, self::DEADLINE_AT,
        );
    }

    /** @return StateHilosProfileNameChange New checking row */
    private function newState(): StateHilosProfileNameChange
    {
        return StateHilosProfileNameChange::create(
            self::SESSION_HASH, self::USER_ID, self::REQUEST_ID, self::ACCEPT_KEY, self::NAME,
            self::STARTED_AT, self::DEADLINE_AT,
        );
    }

    /** @return array<string, mixed> Complete expected runtime row */
    private static function row(): array
    {
        return [
            StateHilosProfileNameChange::sessionTokenHash => self::SESSION_HASH,
            StateHilosProfileNameChange::userId => self::USER_ID,
            StateHilosProfileNameChange::requestId => self::REQUEST_ID,
            StateHilosProfileNameChange::originAcceptKey => self::ACCEPT_KEY,
            StateHilosProfileNameChange::name => self::NAME,
            StateHilosProfileNameChange::phase => StateHilosProfileNameChange::PHASE_CHECKING,
            StateHilosProfileNameChange::refusal => null,
            StateHilosProfileNameChange::startedAt => self::STARTED_AT,
            StateHilosProfileNameChange::deadlineAt => self::DEADLINE_AT,
        ];
    }
}

/** Records membership announcements to pin the remove-before-add replacement contract. */
final class ProfileNameChangeRecorder implements SourceChangeSubscriberInterface
{
    /** @var list<TableMutationType> Announced membership changes in order */
    public array $mutations = [];

    /**
     * @param SourceChange $change Announced source change
     * @param SourceChangeProvenance $provenance Origin of the change
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind === SourceChange::KIND_RT && $change->sourceKey === StateHilosProfileNameChange::RT_COLLECTION) {
            $this->mutations[] = $change->mutationType;
        }
    }
}
