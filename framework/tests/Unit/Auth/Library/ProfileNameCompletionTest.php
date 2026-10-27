<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\ProfileNameCommands;
use Hilos\Auth\Library\DTO\ProfileNameAdvanceSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserSessionsRestateSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/** Completion correlation and announcement failures without replacing the accepted-operation store. */
final class ProfileNameCompletionTest extends TestCase
{
    private const string HASH = 'accepted-browser-hash';
    private const string REQUEST = '0123456789abcdef0123456789abcdef';
    private const string ORIGIN = 'closed-origin-socket';
    private const int USER = 1635;

    private ?RtContext $previousRt;
    private ?SignalRouter $previousRouter;
    private string $previousApp;
    private ProfileNameCompletionLibrary $library;
    private ProfileNameCommands $commands;

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousApp = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, ProfileNameCompletionHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new class extends RtContext {
            /** The sign-in feature supplies every collection this case needs. */
            public function configure(): void
            {
            }
        };
        Hilos::$rt->mountFeatureRuntime([new AuthFeature()]);
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(HilosProfileNameChange::RT_COLLECTION);
        Hilos::$rt->hilosProfileNameChanges->actions->open(self::HASH, self::USER, self::REQUEST, self::ORIGIN, 'Grace', 1, 2);
        Hilos::$rt->hilosProfileNameChanges[self::HASH]->actions->markApplying();
        Hilos::$sr = new SignalRouter();
        $this->library = new ProfileNameCompletionLibrary();
        $this->commands = $this->library->nameCommands();
    }

    protected function tearDown(): void
    {
        RtStaleness::clear(HilosProfileNameChange::RT_COLLECTION, [self::HASH]);
        RtTruthSourceRegistry::unregisterDaemon(HilosProfileNameChange::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousRouter;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
    }

    /** @return iterable<string, array{?int}> A write and the owner's successful no-op */
    public static function successfulReplies(): iterable
    {
        yield 'committed journal' => [42];
        yield 'already equal at owner' => [null];
    }

    #[DataProvider('successfulReplies')]
    public function testCurrentSuccessRestatesOnlyItsPersonBeforeCompletingTheSameAttempt(?int $renameId): void
    {
        $done = UserRenameDoneSignalData::to($this->ask(), $renameId, null);
        $before = $done->toArray();
        $this->commands->renamed($done);

        $restate = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE, $restate->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $restate->data);
        self::assertInstanceOf(UserSessionsRestateSignalData::class, $restate->data->data);
        self::assertSame(self::USER, $restate->data->data->userId);
        $this->assertAdvance(HilosProfileNameChange::PHASE_DONE);
        self::assertSame($before, $done->toArray());
        self::assertSame('applying', Hilos::$rt->hilosProfileNameChanges[self::HASH]->phase);
    }

    public function testOwnerRefusalCompletesWithoutAnnouncingSuccess(): void
    {
        $this->commands->renamed(UserRenameDoneSignalData::to($this->ask(), null, ActionRefusal::said('Name refused.')));
        $this->assertAdvance(HilosProfileNameChange::PHASE_REFUSED, 'Name refused.');
    }

    public function testAnnouncementFailureStillCompletesTheAcceptedAttempt(): void
    {
        $this->library->failRestate = true;
        $this->commands->renamed(UserRenameDoneSignalData::to($this->ask(), 42, null));
        $this->assertAdvance(HilosProfileNameChange::PHASE_DONE);
    }

    /** @return iterable<string, array{array<string, mixed>}> Replies outside the accepted self operation */
    public static function foreignReplies(): iterable
    {
        yield 'another request' => [['requestId' => 'another-request']];
        yield 'legacy self rename' => [['requestId' => null]];
        yield 'another user' => [['userId' => self::USER + 1, 'renamedByUserId' => self::USER + 1]];
        yield 'administrator' => [['renamedByUserId' => self::USER + 1]];
        yield 'another name' => [['name' => 'Another name']];
        yield 'another origin' => [['acceptKey' => 'another-origin']];
        yield 'another action' => [['action' => 'admin_rename']];
    }

    /** @param array<string, mixed> $fields Changed owner ask fields */
    #[DataProvider('foreignReplies')]
    public function testForeignSuccessCannotAnnounceOrCompleteAnything(array $fields): void
    {
        $ask = UserRenameSignalData::fromArray(array_replace($this->ask()->toArray(), $fields));
        $this->commands->renamed(UserRenameDoneSignalData::to($ask, 42, null));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertSame('applying', Hilos::$rt->hilosProfileNameChanges[self::HASH]->phase);
    }

    public function testUnknownFreshnessAndTerminalDuplicatesCannotRestate(): void
    {
        $done = UserRenameDoneSignalData::to($this->ask(), 42, null);
        RtStaleness::mark(HilosProfileNameChange::RT_COLLECTION, [self::HASH], microtime(true));
        $this->commands->renamed($done);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        RtStaleness::clear(HilosProfileNameChange::RT_COLLECTION, [self::HASH]);
        Hilos::$rt->hilosProfileNameChanges[self::HASH]->actions->complete();
        Hilos::$sr = new SignalRouter();
        $this->commands->renamed($done);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return UserRenameSignalData Existing ask of the applying attempt */
    private function ask(): UserRenameSignalData
    {
        return new UserRenameSignalData(
            userId: self::USER, name: 'Grace', renamedByUserId: self::USER,
            replySignal: HilosSignalConstants::HILOS_USER_RENAME_DONE, acceptKey: self::ORIGIN,
            requestId: self::REQUEST, action: HilosSignalConstants::PROFILE_NAME_SET, successMessage: null, answerSignal: null,
        );
    }

    /**
     * @param string $phase Owner outcome
     * @param ?string $refusal Safe refusal, if any
     */
    private function assertAdvance(string $phase, ?string $refusal = null): void
    {
        $advance = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(HilosSignalConstants::HILOS_PROFILE_NAME_ADVANCE, $advance->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $advance->data);
        self::assertInstanceOf(ProfileNameAdvanceSignalData::class, $advance->data->data);
        self::assertSame(self::HASH, $advance->data->data->sessionTokenHash);
        self::assertSame(self::REQUEST, $advance->data->data->requestId);
        self::assertSame($phase, $advance->data->data->phase);
        self::assertSame($refusal, $advance->data->data->refusal);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }
}

/** Keeps production queueing except for the explicit ancillary transport failure. */
final class ProfileNameCompletionLibrary extends AbstractUsersLibraryAgent
{
    public bool $failRestate = false;

    /** @return ProfileNameCommands Completion commands with the production confirmation dependency */
    public function nameCommands(): ProfileNameCommands
    {
        return new ProfileNameCommands($this, $this->stepUpCommands());
    }

    /**
     * @param string $signalName Frame name
     * @param SignalDataInterface $data Existing payload
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        if ($this->failRestate && $signalName === HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE) {
            throw new RuntimeException('Restate transport unavailable');
        }
        parent::sendToAgent($signalName, $data);
    }
}

/** Session-backed profile editing with no moderation feature. */
abstract class ProfileNameCompletionHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
}
