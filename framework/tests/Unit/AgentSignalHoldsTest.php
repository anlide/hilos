<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Daemon\AgentSignalHold;
use Hilos\Core\Daemon\AgentSignalHolds;
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\SignalDispatchPhase;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Files\Upload\UploadMemoryMaster;
use Hilos\Files\Upload\UploadMemoryPool;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use WeakReference;

/** Pending payloads have one owner; queues and cursors retain only scalar identities. */
final class AgentSignalHoldsTest extends TestCase
{
    public function testLiveCandidateCursorCannotPinASettledPayloadOrIncludeFutureHolds(): void
    {
        $holds = new AgentSignalHolds();
        $first = $this->hold($holds, 'first');
        $last = $this->hold($holds, 'last');
        $firstPayload = WeakReference::create($first->getSignal()->data);
        $lastPayload = WeakReference::create($last->getSignal()->data);
        $candidates = $holds->candidates();
        $late = $this->hold($holds, 'late');
        self::assertSame($first->id, $candidates->current());
        $holds->settle($first->id);
        self::assertNull($firstPayload->get(), 'A suspended finite scan must not retain a deleted raw envelope');
        self::assertNotNull($lastPayload->get());
        $candidates->next();
        self::assertSame($last->id, $candidates->current());
        $holds->settle($last->id);
        self::assertNull($lastPayload->get());
        $candidates->next();
        self::assertFalse($candidates->valid());
        self::assertNotNull($holds->get($late->id));
    }

    public function testReleasedFifoKeepsItsHeadThroughCancellationAndReparking(): void
    {
        $holds = new AgentSignalHolds();
        $first = $this->hold($holds, 'first');
        $middle = $this->hold($holds, 'middle');
        $last = $this->hold($holds, 'last');
        $holds->release($middle->id);
        $holds->release($first->id);
        $holds->release($last->id);
        self::assertSame(0, $holds->getParkedCount());
        self::assertSame($middle->id, $holds->firstReleasedId());
        $holds->settle($middle->id);
        self::assertSame($first->id, $holds->firstReleasedId());
        $signal = $first->getSignal();
        $cursor = new SignalDispatch($signal, static function (): Generator { yield; yield; });
        $holds->begin($first->id, $cursor);
        self::assertSame($last->id, $holds->firstReleasedId());
        self::assertSame(['owner'], $holds->pendingAgentIds(), 'An active cursor remains an agent blocker');
        $holds->park($first->id, new ParkedAgentSignal($signal, 'owner', 0.0, true));
        self::assertSame(1, $holds->getParkedCount());
        self::assertSame($first, $holds->get($first->id));
        $holds->settle($last->id);
        self::assertFalse($holds->hasReleased());
        $holds->release($first->id);
        self::assertSame($first->id, $holds->firstReleasedId());
    }

    public function testPrefixIndexesKeepOriginalOrderAndChildDebtWithoutRetainingSettledRaw(): void
    {
        $holds = new AgentSignalHolds();
        $first = $this->hold($holds, 'first');
        $cutoff = $holds->getWatermark();
        $child = $holds->add(new ParkedAgentSignal($first->getSignal(), 'owner', 0.0, false), 4, 9);
        $unrelated = $this->hold($holds, 'unrelated');
        $last = $holds->add(new ParkedAgentSignal($unrelated->getSignal(), 'owner', 0.0, false), 4);
        self::assertSame([$first->id, $child->id, $last->id], iterator_to_array($holds->prefixCandidates($cutoff, 4), false));
        self::assertSame([$first->id, $child->id], iterator_to_array($holds->prefixCandidates($cutoff, 9), false));
        $raw = WeakReference::create($first->getSignal()->data);
        $candidates = $holds->prefixCandidates($cutoff, 4);
        self::assertSame($first->id, $candidates->current());
        $holds->settle($first->id);
        $candidates->next();
        self::assertSame($child->id, $candidates->current());
        $holds->release($child->id);
        $dispatch = new SignalDispatch($child->getSignal(), static function (): Generator { yield; yield; });
        $holds->begin($child->id, $dispatch);
        self::assertSame([4, 9], array_keys(new ReflectionProperty(AgentSignalHolds::class, 'byPrefix')->getValue($holds)));
        $holds->settle($child->id);
        self::assertNull($raw->get(), 'A suspended prefix query and both scalar indexes cannot keep the original raw alive');
        $candidates->next();
        self::assertSame($last->id, $candidates->current());
        $holds->settle($last->id);
        $candidates->next();
        self::assertFalse($candidates->valid());
        self::assertSame([], new ReflectionProperty(AgentSignalHolds::class, 'byPrefix')->getValue($holds));
        self::assertSame([], iterator_to_array($holds->prefixCandidates($cutoff, 4), false));
        self::assertSame($unrelated, $holds->get($unrelated->id));
    }

    public function testCancelingAnActiveHoldReleasesItsEnvelopeAndKeepsOtherHolds(): void
    {
        $holds = new AgentSignalHolds();
        $first = $this->hold($holds, 'first');
        $last = $this->hold($holds, 'last');
        $payload = WeakReference::create($first->getSignal()->data);
        $holds->release($first->id);
        $cursor = new SignalDispatch($first->getSignal(), static function (): Generator { yield; yield; });
        $holds->begin($first->id, $cursor);
        $holds->settle($first->id);
        self::assertNull($payload->get());
        self::assertSame(SignalDispatchPhase::Canceled, $cursor->phase);
        self::assertSame($last, $holds->get($last->id));
        self::assertSame(1, $holds->getParkedCount());
        self::assertFalse($holds->hasReleased());
    }

    public function testCorrelationInvalidationIsImmediateAndItsFiniteCleanupCannotPinSettledEnvelopes(): void
    {
        $holds = new AgentSignalHolds();
        $original = [];
        for ($index = 0; $index < 130; $index++) {
            $original[] = $holds->add(new ParkedAgentSignal(new SignalDTO(
                new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::COMMAND_REQUEST),
                new SignalName('cancel:request'), new CommandRequestDTO('caller', 'cancel:request'),
            ), 'owner', 0.0, false));
        }
        $first = WeakReference::create($original[0]->getSignal()->data);
        $last = WeakReference::create($original[129]->getSignal()->data);
        $cleanup = $holds->cancelCorrelation('caller');
        self::assertTrue($holds->isCanceled($original[0]));
        self::assertTrue($holds->isCanceled($original[129]));
        self::assertNotNull($first->get());
        $late = $holds->add(new ParkedAgentSignal(new SignalDTO(
            new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName('cancel:request'), new CommandRequestDTO('caller', 'cancel:request'),
        ), 'owner', 0.0, false));
        self::assertFalse($holds->isCanceled($late));
        $dispatch = new SignalDispatch(null, static fn() => $cleanup);
        $dispatch->runPortion(64);
        self::assertNull($first->get(), 'The cleanup cursor contains scalar identities, not its completed DTOs');
        self::assertNotNull($last->get());
        self::assertSame(67, $holds->getParkedCount());
        $dispatch->runPortion(128);
        self::assertNull($last->get());
        self::assertSame(1, $holds->getParkedCount());
        self::assertSame($late, $holds->get($late->id));
        self::assertFalse($holds->isCanceled($late));
        self::assertFalse($dispatch->hasWork());
    }

    public function testOneCancellationStepDoesNotCopyTheWholeScalarCohort(): void
    {
        $holds = new AgentSignalHolds();
        $parked = new ParkedAgentSignal(new SignalDTO(
            new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName('cancel:request'), new CommandRequestDTO('caller', 'cancel:request'),
        ), 'owner', 0.0, false);
        for ($index = 0; $index < 20000; $index++) {
            $holds->add($parked);
        }
        $cleanup = $holds->cancelCorrelation('caller');
        $cleanup->current();
        $baseline = memory_get_usage();
        memory_reset_peak_usage();
        $cleanup->next();
        $growth = memory_get_peak_usage() - $baseline;
        self::assertLessThan(256 * 1024, $growth, 'One physical settlement must not copy a 20,000-entry scalar index');
        self::assertSame(19999, $holds->getParkedCount());
    }

    public function testPageInvalidationIsImmediateAndItsCleanupPreservesNewerAndUnrelatedHolds(): void
    {
        $holds = new AgentSignalHolds();
        $first = $this->pageHold($holds, 'socket', 'p');
        $otherPage = $this->pageHold($holds, 'socket', 'q');
        $otherSocket = $this->pageHold($holds, 'other', 'p');
        $last = $this->pageHold($holds, 'socket', 'p');
        $payload = WeakReference::create($first->getSignal()->data);
        $holds->release($first->id);
        $cursor = new SignalDispatch($first->getSignal(), static function (): Generator { yield; self::fail('Canceled work ran'); });
        $holds->begin($first->id, $cursor);
        $settled = [];
        $cleanup = $holds->cancelConnection('socket', 'p', static function (AgentSignalHold $hold) use (&$settled): void {
            self::assertNotNull($hold->getSignal(), 'Prefix transfer precedes payload release');
            $settled[] = $hold->id;
        });
        self::assertTrue($holds->isCanceled($first));
        self::assertTrue($holds->isCanceled($last));
        self::assertFalse($holds->isCanceled($otherPage));
        self::assertFalse($holds->isCanceled($otherSocket));
        self::assertNotNull($payload->get());
        $newer = $this->pageHold($holds, 'socket', 'p');
        self::assertFalse($holds->isCanceled($newer));
        $cleanup->current();
        $cleanup->next();
        self::assertNull($payload->get());
        self::assertFalse($cursor->hasWork());
        self::assertSame(SignalDispatchPhase::Canceled, $cursor->phase);
        self::assertNotNull($holds->get($last->id));
        while ($cleanup->valid()) {
            $cleanup->next();
        }
        self::assertSame([$first->id, $last->id], $settled);
        self::assertSame([$otherPage->id, $newer->id], iterator_to_array($holds->connectionCandidates('socket'), false));
        self::assertSame($otherSocket, $holds->get($otherSocket->id));
        self::assertFalse($holds->isCanceled($newer));
    }

    public function testAnOlderConnectionCleanupCannotClearANewerInvalidationOrCancelAReplacement(): void
    {
        $holds = new AgentSignalHolds();
        $first = $this->pageHold($holds, 'socket', 'p');
        $older = $holds->cancelConnection('socket');
        $middle = $this->pageHold($holds, 'socket', 'q');
        $newer = $holds->cancelConnection('socket');
        $replacement = $this->pageHold($holds, 'socket', 'p');
        self::assertTrue($holds->isCanceled($first));
        self::assertTrue($holds->isCanceled($middle));
        self::assertFalse($holds->isCanceled($replacement));
        iterator_to_array($older);
        self::assertNull($holds->get($first->id));
        self::assertTrue($holds->isCanceled($middle), 'Finishing the old generation must retain the newer invalidation');
        iterator_to_array($newer);
        self::assertNull($holds->get($middle->id));
        self::assertSame($replacement, $holds->get($replacement->id));
        self::assertFalse($holds->isCanceled($replacement));
        self::assertSame([$replacement->id], iterator_to_array($holds->connectionCandidates('socket'), false));
    }

    public function testASuspendedScalarIndexSkipsDeletedMembersWithoutCapturingFutureAdmissions(): void
    {
        $holds = new AgentSignalHolds();
        $first = $this->pageHold($holds, 'socket', 'p');
        $last = $this->pageHold($holds, 'socket', 'p');
        $candidates = $holds->connectionCandidates('socket');
        self::assertSame($first->id, $candidates->current());
        $holds->settle($first->id);
        $holds->settle($last->id);
        $replacement = $this->pageHold($holds, 'socket', 'p');
        $candidates->next();
        self::assertFalse($candidates->valid());
        self::assertSame([$replacement->id], iterator_to_array($holds->connectionCandidates('socket'), false));
    }

    public function testActualReceiptCanReleaseBeforeTheFiniteHoldScanReachesItsEnd(): void
    {
        $memory = new UploadMemoryMaster('hold-scan');
        $pool = new ReflectionProperty(UploadMemoryMaster::class, 'pool')->getValue($memory);
        self::assertInstanceOf(UploadMemoryPool::class, $pool);
        $pool->setLimitBytes(3);
        $pool->request('browser', 'file', 3);
        $reservation = $pool->reserveNext();
        self::assertNotNull($reservation);
        $pool->publish($reservation->id);
        $receipt = $pool->acceptPending($reservation->requestId, 0, 3);
        self::assertNotNull($receipt);
        $holds = new AgentSignalHolds();
        $first = $this->hold($holds, 'abc');
        $last = $this->hold($holds, 'still-held');
        $memory->onUploadFrameQueued($first->getSignal()->data, $receipt);
        $raw = WeakReference::create($first->getSignal()->data);
        $scan = $holds->candidates();
        self::assertSame($first->id, $scan->current());
        $memory->afterDispatch();
        self::assertSame(3, $pool->getUsedBytes());
        $holds->settle($first->id);
        self::assertNull($raw->get());
        $memory->afterDispatch();
        self::assertSame(0, $pool->getUsedBytes());
        self::assertTrue($scan->valid());
        $scan->next();
        self::assertSame($last->id, $scan->current());
        self::assertNotNull($last->getSignal());
    }

    /** @return iterable<string, array{bool}> Caller and page cancellation indexes */
    public static function throwingCancellations(): iterable
    {
        yield 'caller' => [false];
        yield 'page' => [true];
    }

    /** @param bool $page Whether the original obligations belong to one browser page */
    #[DataProvider('throwingCancellations')]
    public function testThrowingCancellationFinishesItsOriginalCohortBeforeReportingTheFirstFailure(bool $page): void
    {
        $holds = new AgentSignalHolds();
        $original = [];
        $references = [];
        $lifetimes = [];
        $first = new RuntimeException('first canceled finalizer');
        for ($index = 0; $index < 4; $index++) {
            $failure = $index === 0 ? $first : new RuntimeException('later canceled finalizer');
            $signal = new SignalDTO(new SignalSource(SignalSource::WEBSOCKET),
                new SignalType($page ? SignalTypeConstants::PAGE_SUBSCRIBE : SignalTypeConstants::COMMAND_REQUEST),
                new SignalName($page ? 'p' : 'cancel:request'),
                $page ? new WebSocketPageSubscribeSignalDTO('socket', 'p') : new CommandRequestDTO('caller', 'cancel:request'));
            $hold = $holds->add(new ParkedAgentSignal($signal, 'owner', 0.0, false));
            if ($index === 3) {
                $future = $hold;
                break;
            }
            $references[] = WeakReference::create($signal->data);
            $dispatch = new SignalDispatch($signal, fn(): Generator => $this->throwingHoldWork($signal, $failure));
            $dispatch->runPortion(1);
            $holds->release($hold->id);
            $holds->begin($hold->id, $dispatch);
            $original[] = $hold;
            $lifetimes[] = $dispatch;
            if ($index === 2) {
                $cleanup = $page ? $holds->cancelConnection('socket', 'p') : $holds->cancelCorrelation('caller');
            }
        }
        unset($signal, $dispatch, $hold);
        $caught = null;
        try {
            try {
                foreach ($cleanup as $_) {
                }
            } catch (Throwable $failure) {
                $caught = $failure;
            }
            self::assertSame($first, $caught);
            foreach ($original as $hold) {
                self::assertNull($holds->get($hold->id));
                self::assertNull($hold->getSignal());
            }
            foreach ($references as $reference) {
                self::assertNull($reference->get(), 'A canceled throwing cursor cannot retain its request after cohort settlement');
            }
            self::assertSame($future, $holds->get($future->id));
            self::assertFalse($holds->isCanceled($future));
            self::assertSame(1, $holds->getParkedCount());
            self::assertFalse($holds->hasReleased());
        } finally {
            foreach ($lifetimes as $dispatch) {
                try {
                    $dispatch->cancel();
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * @param SignalDTO $signal Original request held by the physical continuation
     * @param Throwable $failure Finalizer failure after the cancellation fact
     * @return Generator<int, void> Suspended original lifetime
     */
    private function throwingHoldWork(SignalDTO $signal, Throwable $failure): Generator
    {
        try {
            yield;
            yield;
        } finally {
            throw $failure;
        }
    }

    /**
     * @param AgentSignalHolds $holds Existing owner
     * @param string $acceptKey Original browser lifetime
     * @param string $page Requested page
     * @return AgentSignalHold New page obligation
     */
    private function pageHold(AgentSignalHolds $holds, string $acceptKey, string $page): AgentSignalHold
    {
        return $holds->add(new ParkedAgentSignal(new SignalDTO(new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE), new SignalName($page),
            new WebSocketPageSubscribeSignalDTO($acceptKey, $page)), 'owner', 0.0, false));
    }

    /**
     * @param AgentSignalHolds $holds Existing owner
     * @param string $marker Distinct raw payload
     * @return AgentSignalHold New pending obligation
     */
    private function hold(AgentSignalHolds $holds, string $marker): AgentSignalHold
    {
        return $holds->add(new ParkedAgentSignal(new SignalDTO(new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::FRAME_BINARY), new SignalName('bytes'),
            new WebSocketFrameBinarySignalDTO('browser', $marker)), 'owner', 0.0, false));
    }
}
