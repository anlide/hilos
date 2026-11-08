<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Daemon\AgentSignalHolds;
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\SignalDispatchIntake;
use Hilos\Core\Daemon\SignalDispatchPrefix;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeakReference;

/** Finite metadata invariants; native fixtures own the actual master/server shutdown ordering. */
final class SignalDispatchPrefixTest extends TestCase
{
    public function testCapturedInputFinishesWithoutWaitingForContinuousNewIntake(): void
    {
        $router = new SignalRouter();
        $this->queue($router, 'first');
        $this->queue($router, 'last');
        $intake = new SignalDispatchIntake(1, $router);
        $prefix = new SignalDispatchPrefix(1, $intake, [], 0, 0, 0);
        $holds = new AgentSignalHolds();
        for ($position = 1; $position <= 2; $position++) {
            $signal = $intake->consumeSignal();
            self::assertNotNull($signal);
            $dispatch = $this->cursor($signal);
            $dispatch->intakeId = $intake->id;
            $dispatch->inputPosition = $intake->consumed;
            $prefix->track($dispatch);
            $this->queue($router, 'new-' . $position);
            $dispatch->runPortion(64);
            $prefix->settle($dispatch);
        }
        self::assertFalse($prefix->includesInput(1, 3));
        $this->validate($prefix, $holds);
        self::assertTrue($prefix->isReadyForAccounting());
        self::assertSame(2, $router->getQueuedSignalCount());
        $prefix->completeAfterAccounting();
        self::assertTrue($prefix->complete);
    }

    public function testGeneratedChildInputDoesNotGiveUnrelatedLaterInputPrefixOwnership(): void
    {
        $router = new SignalRouter();
        $intake = new SignalDispatchIntake(1, $router);
        $prefix = new SignalDispatchPrefix(1, $intake, [], 0, 0, 0);
        $this->queue($router, 'unrelated');
        $this->queue($router, 'child');
        $this->queue($router, 'later');
        $prefix->addChildren($intake, 2, 2);
        $unrelated = $this->cursor($intake->consumeSignal());
        $unrelated->intakeId = 1;
        $unrelated->inputPosition = 1;
        self::assertFalse($prefix->track($unrelated));
        $this->validate($prefix, new AgentSignalHolds());
        self::assertFalse($prefix->isReadyForAccounting());
        $child = $this->cursor($intake->consumeSignal());
        $child->intakeId = 1;
        $child->inputPosition = 2;
        self::assertTrue($prefix->track($child));
        $child->runPortion(64);
        $prefix->settle($child);
        $this->validate($prefix, new AgentSignalHolds());
        self::assertTrue($prefix->isReadyForAccounting());
        self::assertSame('later', $router->getNextQueuedSignal()->signalName->getName());
    }

    public function testActiveLifetimeBlocksCompletionWithoutPinningItsCompletedPayload(): void
    {
        $prefix = new SignalDispatchPrefix(1, new SignalDispatchIntake(1, new SignalRouter()), [], 0, 0, 0);
        $signal = $this->signal('active');
        $payload = WeakReference::create($signal->data);
        $dispatch = $this->cursor($signal);
        unset($signal);
        $prefix->captureActive($dispatch);
        self::assertFalse($prefix->hasRunnableValidation());
        $dispatch->runPortion(1);
        self::assertTrue($dispatch->hasWork());
        self::assertFalse($prefix->isReadyForAccounting());
        $dispatch->runPortion(64);
        self::assertNull($payload->get(), 'Prefix memory must not retain completed envelopes');
        $prefix->settle($dispatch);
        $this->validate($prefix, new AgentSignalHolds());
        self::assertTrue($prefix->isReadyForAccounting());
    }

    public function testAnActiveReleasedJournalRemainsABlockerUntilItsActualSettlement(): void
    {
        $holds = new AgentSignalHolds();
        $hold = $holds->add(new ParkedAgentSignal($this->signal('held'), 'journal', 0.0, false));
        $worker = new PrefixMetadataWorkerServer();
        $prefix = new SignalDispatchPrefix(1, new SignalDispatchIntake(1, new SignalRouter()), [], $hold->id, 0, 0, $worker, 3);
        $holds->release($hold->id);
        $dispatch = $this->cursor($hold->getSignal());
        $holds->begin($hold->id, $dispatch);
        self::assertSame(0, $holds->getParkedCount());
        $this->validate($prefix, $holds);
        self::assertFalse($prefix->isReadyForAccounting());
        self::assertFalse($prefix->hasRunnableValidation(), 'An unchanged blocked journal is not runnable');
        $dispatch->runPortion(64);
        $holds->settle($hold->id);
        $prefix->noteHoldChanged($hold);
        $this->validate($prefix, $holds);
        self::assertTrue($prefix->isReadyForAccounting());
    }

    public function testCapturedChildHoldBlocksButUnrelatedPostPrefixTrafficDoesNot(): void
    {
        $holds = new AgentSignalHolds();
        $prefix = new SignalDispatchPrefix(7, new SignalDispatchIntake(1, new SignalRouter()), [], 0, 0, 0,
            new PrefixMetadataWorkerServer(), 3);
        $captured = $holds->add(new ParkedAgentSignal($this->signal('captured'), 'journal', 0.0, false), 7);
        $prefix->noteHoldChanged($captured);
        $this->validate($prefix, $holds);
        self::assertFalse($prefix->isReadyForAccounting());
        $late = $holds->add(new ParkedAgentSignal($this->signal('late'), 'journal', 0.0, false));
        $prefix->noteHoldChanged($late);
        self::assertFalse($prefix->hasRunnableValidation());
        $holds->settle($captured->id);
        $prefix->noteHoldChanged($captured);
        $this->validate($prefix, $holds);
        self::assertTrue($prefix->isReadyForAccounting());
        self::assertSame($late, $holds->get($late->id));
    }

    /** @return iterable<string, array{bool}> Worker stop and independent final-browser ownership */
    public static function indexedHoldPrefixes(): iterable
    {
        yield 'worker stop' => [false];
        yield 'final browser' => [true];
    }

    /** @param bool $browser Whether the original debt belongs to the independent browser boundary */
    #[DataProvider('indexedHoldPrefixes')]
    public function testValidationVisitsOnlyOriginalHoldsAndTheirChildrenWhileUnrelatedHoldsKeepArriving(bool $browser): void
    {
        $holds = new AgentSignalHolds();
        $original = $holds->add(new ParkedAgentSignal($this->signal('original'), 'journal', 0.0, false));
        $holds->release($original->id);
        $prefix = new SignalDispatchPrefix(7, new SignalDispatchIntake(1, new SignalRouter()), [], $holds->getWatermark(), 0, 0,
            $browser ? null : new PrefixMetadataWorkerServer(), $browser ? null : 3);
        $child = $holds->add(new ParkedAgentSignal($this->signal('child'), 'journal', 0.0, false),
            $browser ? null : $prefix->id, $browser ? $prefix->id : null);
        $holds->release($child->id);
        $prefix->noteHoldChanged($child);
        for ($index = 0; $index < 1000; $index++) {
            $later = $holds->add(new ParkedAgentSignal($this->signal('later'), 'journal', 0.0, false));
            $holds->release($later->id);
        }
        for ($step = 0; $step < 8; $step++) {
            $prefix->advanceValidation($holds);
        }
        self::assertFalse($prefix->isReadyForAccounting());
        self::assertFalse($prefix->hasRunnableValidation(), 'A known original blocker needs no scan through unrelated later holds');
        foreach ([$original, $child] as $captured) {
            $holds->settle($captured->id);
            $prefix->noteHoldChanged($captured);
            for ($step = 0; $step < 8; $step++) {
                $later = $holds->add(new ParkedAgentSignal($this->signal('ongoing'), 'journal', 0.0, false));
                $holds->release($later->id);
                $prefix->noteHoldChanged($later);
                $prefix->advanceValidation($holds);
            }
            self::assertSame($captured === $child, $prefix->isReadyForAccounting(), 'The original child retains its separate debt');
        }
        self::assertTrue($holds->hasReleased());
        self::assertFalse($prefix->hasRunnableValidation());
        $prefix->completeAfterAccounting();
        self::assertTrue($prefix->complete);
        self::assertNotNull($holds->get($later->id));
    }

    public function testRetiredRouterTailDoesNotAbsorbLateInputOnTheDetachedIncarnation(): void
    {
        $oldRouter = new SignalRouter();
        $this->queue($oldRouter, 'old-prefix');
        $old = new SignalDispatchIntake(1, $oldRouter);
        $old->retire();
        $this->queue($oldRouter, 'detached-late');
        $router = new SignalRouter();
        $this->queue($router, 'new-prefix');
        $current = new SignalDispatchIntake(2, $router);
        $prefix = new SignalDispatchPrefix(1, $current, [1 => $old], 0, 0, 0);
        self::assertSame('old-prefix', $old->consumeSignal()->signalName->getName());
        self::assertNull($old->consumeSignal());
        self::assertSame('new-prefix', $current->consumeSignal()->signalName->getName());
        $this->queue($router, 'new-late');
        $this->validate($prefix, new AgentSignalHolds());
        self::assertTrue($prefix->isReadyForAccounting());
        self::assertSame('detached-late', $oldRouter->getNextQueuedSignal()->signalName->getName());
        self::assertSame(1, $router->getQueuedSignalCount());
    }

    public function testCapturedLifecycleCohortAndItsChildReplyRemainPartOfTheFinitePrefix(): void
    {
        $router = new SignalRouter();
        $intake = new SignalDispatchIntake(1, $router);
        $prefix = new SignalDispatchPrefix(1, $intake, [], 0, 0, 0, effectWatermark: 4);
        $holds = new AgentSignalHolds();
        for ($step = 0; $step < 8 && $prefix->hasRunnableValidation(); $step++) {
            $prefix->advanceValidation($holds, firstEffect: 4);
        }
        self::assertFalse($prefix->isReadyForAccounting());
        self::assertFalse($prefix->hasRunnableValidation());
        $effect = new SignalDispatch(null, static function (): Generator { yield; yield; });
        $effect->effectId = 4;
        self::assertTrue($prefix->track($effect));
        $this->queue($router, 'unrelated');
        $this->queue($router, 'refusal');
        $prefix->addChildren($intake, 2, 2);
        $effect->runPortion(64);
        $prefix->settle($effect);
        $this->validate($prefix, $holds);
        self::assertFalse($prefix->isReadyForAccounting());
        $intake->consumeSignal();
        $reply = $this->cursor($intake->consumeSignal());
        $reply->intakeId = 1;
        $reply->inputPosition = 2;
        self::assertTrue($prefix->track($reply));
        $late = new SignalDispatch(null, static function (): Generator { yield; yield; });
        $late->effectId = 5;
        self::assertFalse($prefix->track($late));
        $reply->runPortion(64);
        $prefix->settle($reply);
        $this->validate($prefix, $holds);
        self::assertTrue($prefix->isReadyForAccounting());
        self::assertTrue($late->hasWork());
    }

    public function testStaleStopGenerationCannotComplete(): void
    {
        $worker = new PrefixMetadataWorkerServer();
        $prefix = new SignalDispatchPrefix(1, new SignalDispatchIntake(1, new SignalRouter()), [], 0, 0, 0, $worker, 3);
        $worker->generation = 4;
        $this->validate($prefix, new AgentSignalHolds());
        self::assertFalse($prefix->isReadyForAccounting());
        $this->expectException(LogicException::class);
        $prefix->completeAfterAccounting();
    }

    public function testAFailedPrefixCannotBeAcknowledgedByALaterEmptyPass(): void
    {
        $prefix = new SignalDispatchPrefix(1, new SignalDispatchIntake(1, new SignalRouter()), [], 0, 0, 0);
        $this->validate($prefix, new AgentSignalHolds());
        self::assertTrue($prefix->isReadyForAccounting());
        $prefix->fail();
        self::assertFalse($prefix->hasRunnableValidation());
        self::assertFalse($prefix->isReadyForAccounting());
        $this->expectException(LogicException::class);
        $prefix->completeAfterAccounting();
    }

    /**
     * @param SignalDispatchPrefix $prefix Captured metadata owner
     * @param AgentSignalHolds $holds Pending payload owner
     */
    private function validate(SignalDispatchPrefix $prefix, AgentSignalHolds $holds): void
    {
        for ($step = 0; $step < 1000 && $prefix->hasRunnableValidation(); $step++) {
            $prefix->advanceValidation($holds);
        }
        self::assertFalse($prefix->hasRunnableValidation(), 'A finite metadata pass must end');
    }

    /**
     * @param SignalDTO $signal Ordinary envelope
     * @return SignalDispatch Two bounded steps before settlement
     */
    private function cursor(SignalDTO $signal): SignalDispatch
    {
        return new SignalDispatch($signal, static function (): Generator { yield; yield; });
    }

    /**
     * @param SignalRouter $router Existing queue owner
     * @param string $marker Distinct FIFO label
     */
    private function queue(SignalRouter $router, string $marker): void
    {
        $signal = $this->signal($marker);
        $router->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
    }

    /**
     * @param string $marker Distinct FIFO label
     * @return SignalDTO Ordinary process-local envelope
     */
    private function signal(string $marker): SignalDTO
    {
        return new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($marker), new SignalData());
    }
}

/** Socket-free metadata fixture; native shutdown ordering remains the real server fixture's duty. */
final class PrefixMetadataWorkerServer extends WorkerServer
{
    public ?int $generation = 3;

    /** Leaves process and transport ownership to the existing native barrier fixtures. */
    public function __construct()
    {
    }

    /** @return ?int Current simulated owner generation */
    public function pendingStopDispatch(): ?int
    {
        return $this->generation;
    }

    /**
     * @param int $generation Exact owner generation
     * @param string $agentId Addressed agent
     * @return bool Whether the current generation waits for this journal
     */
    public function isStopDispatchAgent(int $generation, string $agentId): bool
    {
        return $generation === $this->generation && $agentId === 'journal';
    }

    /** The metadata fixture opens no listener or process pool. */
    protected function onStart(): void
    {
    }
}
