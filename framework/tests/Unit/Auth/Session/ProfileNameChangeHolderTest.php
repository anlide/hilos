<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\ProfileNameAdvanceSignalData;
use Hilos\Auth\Library\DTO\ProfileNameBeginSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** Public projection and guards that need no DB; admission and valid transitions use real sessions in integration. */
final class ProfileNameChangeHolderTest extends TestCase
{
    private const string HASH = 'session-hash';
    private ?RtContext $previousRt;
    private ?SignalRouter $previousRouter;
    private string $previousApp;
    private ProfileNameChangeTestHolder $holder;

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousApp = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, ProfileNameChangeTestHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new class extends RtContext {
            public function configure(): void
            {
            }
        };
        Hilos::$rt->mountFeatureRuntime([new AuthFeature()]);
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(HilosProfileNameChange::RT_COLLECTION);
        $this->holder = new ProfileNameChangeTestHolder();
        Hilos::$rt->hilosProfileNameChanges->actions->open(self::HASH, 7, str_repeat('a', 32), 'old-socket', 'Grace', 1, 2);
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

    public function testSnapshotProjectsPublicFieldsAndAddressesOnlyOneBrowser(): void
    {
        $this->publish(7);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(HilosSignalConstants::HILOS_PROFILE_NAME_STATE, $signal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertSame(self::HASH, $signal->data->targetSessionTokenHash);
        self::assertNull($signal->data->targetGroup);
        self::assertSame([
            'userId' => 7,
            'change' => ['requestId' => str_repeat('a', 32), 'name' => 'Grace', 'phase' => 'checking', 'refusal' => null],
        ], $signal->data->data->toArray());
    }

    public function testAnotherPersonGetsNoPreviousAttempt(): void
    {
        $this->publish(8);
        self::assertSame(['userId' => 8, 'change' => null], Hilos::$sr->getNextQueuedSignal()->data->data->toArray());
    }

    public function testAStaleAttemptNeverPublishesAnEmptyOrOldSnapshot(): void
    {
        RtStaleness::mark(HilosProfileNameChange::RT_COLLECTION, [self::HASH], microtime(true));
        $this->publish(7);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testLateAndTerminalAdvancesCannotWriteOrRestartAnything(): void
    {
        $this->advance('another-request', HilosProfileNameChange::PHASE_APPLYING);
        self::assertSame('checking', Hilos::$rt->hilosProfileNameChanges[self::HASH]->phase);
        Hilos::$rt->hilosProfileNameChanges[self::HASH]->actions->complete();
        $this->advance(str_repeat('a', 32), HilosProfileNameChange::PHASE_REFUSED, 'An old refusal.');
        self::assertSame('done', Hilos::$rt->hilosProfileNameChanges[self::HASH]->phase);
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(HilosSignalConstants::HILOS_PROFILE_NAME_STATE, $signal->signalName->getName());
            self::assertNotSame(HilosSignalConstants::HILOS_PROFILE_NAME_CONTINUE, $signal->signalName->getName());
        }
    }

    public function testDoneCannotPrecedeTheOwnersApplyingPhase(): void
    {
        $this->advance(str_repeat('a', 32), HilosProfileNameChange::PHASE_DONE);
        self::assertSame('checking', Hilos::$rt->hilosProfileNameChanges[self::HASH]->phase);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testCheckingDeadlineRefusesWithoutReadingASession(): void
    {
        new ReflectionMethod(AbstractSessionsLibraryAgent::class, 'sweepProfileNameChanges')->invoke($this->holder);
        self::assertSame('refused', Hilos::$rt->hilosProfileNameChanges[self::HASH]->phase);
        self::assertSame('Names cannot be checked right now.', Hilos::$rt->hilosProfileNameChanges[self::HASH]->refusal);
        self::assertNull(Hilos::$rt->hilosProfileNameChanges[self::HASH]->deadlineAt);
        self::assertSame(SignalConstants::RT_SYNC_UPDATED, Hilos::$sr->getNextQueuedSignal()->signalName->getName());
        self::assertSame(HilosSignalConstants::HILOS_PROFILE_NAME_STATE, Hilos::$sr->getNextQueuedSignal()->signalName->getName());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testStaleCheckingAndApplyingHaveNoModerationExpiry(): void
    {
        RtStaleness::mark(HilosProfileNameChange::RT_COLLECTION, [self::HASH], microtime(true));
        new ReflectionMethod(AbstractSessionsLibraryAgent::class, 'sweepProfileNameChanges')->invoke($this->holder);
        self::assertSame('checking', Hilos::$rt->hilosProfileNameChanges[self::HASH]->phase);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        RtStaleness::clear(HilosProfileNameChange::RT_COLLECTION, [self::HASH]);
        Hilos::$rt->hilosProfileNameChanges[self::HASH]->actions->markApplying();
        self::assertSame(SignalConstants::RT_SYNC_UPDATED, Hilos::$sr->getNextQueuedSignal()->signalName->getName());
        new ReflectionProperty(AbstractSessionsLibraryAgent::class, 'lastProfileNameSweepAt')->setValue($this->holder, 0.0);
        new ReflectionMethod(AbstractSessionsLibraryAgent::class, 'sweepProfileNameChanges')->invoke($this->holder);
        self::assertSame('applying', Hilos::$rt->hilosProfileNameChanges[self::HASH]->phase);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testNoAuthReadsNoNameCollectionAndReservesNoAttempt(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, Hilos::class);
        Hilos::$rt = new class extends RtContext {
            public function configure(): void
            {
            }
        };
        $this->holder->onSignalAgent(new AgentSignalData(new ProfileNameBeginSignalData('token', 7, 'Grace', 'socket')),
            'users', HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN);
        $this->advance(str_repeat('a', 32), HilosProfileNameChange::PHASE_APPLYING);
        self::assertNotContains(HilosProfileNameChange::RT_COLLECTION, Hilos::$rt->collectionKeys());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @param ?int $userId Snapshot identity */
    private function publish(?int $userId): void
    {
        new ReflectionMethod(AbstractSessionsLibraryAgent::class, 'publishProfileNameChange')->invoke($this->holder, self::HASH, $userId);
    }

    /**
     * @param string $requestId Attempt
     * @param string $phase Reported phase
     * @param ?string $refusal Refusal words
     */
    private function advance(string $requestId, string $phase, ?string $refusal = null): void
    {
        $this->holder->onSignalAgent(new AgentSignalData(new ProfileNameAdvanceSignalData(self::HASH, $requestId, $phase, $refusal)),
            'users', HilosSignalConstants::HILOS_PROFILE_NAME_ADVANCE);
    }
}

abstract class ProfileNameChangeTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
}

final class ProfileNameChangeTestHolder extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}
