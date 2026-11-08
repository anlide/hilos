<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\SignalDispatchPhase;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

/** Local qualification metadata is observable only within one process, without a timer or a second process. */
final class GroupOperationAuthorityTest extends TestCase
{
    private ?SignalRouter $previousRouter;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
    }

    public function testObserversShareOnlyTheirExactNameAndReleaseTheirLastSlot(): void
    {
        $first = Hilos::$sr->groupOperationAuthority('C', 'g', 'g:7');
        $second = Hilos::$sr->groupOperationAuthority('C', 'g', 'g:7');
        $this->join('C', 'other');
        $this->join('D', 'g:7');
        $this->join('C', 'g:8');
        self::assertTrue($first->isCurrent());
        self::assertTrue($second->isCurrent());
        unset($first);
        self::assertTrue($second->isCurrent());
        unset($second);
        $this->assertEmptyObservations();
    }

    public function testAReplacedObservationCannotEraseTheNewLifetimeAtItsRetirement(): void
    {
        $old = Hilos::$sr->groupOperationAuthority('C', 'g', 'g:7');
        $this->join('C', 'g:7');
        self::assertFalse($old->isCurrent());
        $fresh = Hilos::$sr->groupOperationAuthority('C', 'g', 'g:7');
        unset($old);
        self::assertTrue($fresh->isCurrent());
        $this->join('C', 'g');
        self::assertFalse($fresh->isCurrent(), 'A new exact alias changes the worker target of the original head request');
        unset($fresh);
        $this->assertEmptyObservations();
    }

    public function testAbsenceWatchesDescendantsAndLeaveAllEvenWhenTheSetIsEmpty(): void
    {
        $absent = Hilos::$sr->groupOperationAuthority('C', 'g', null);
        $this->join('C', 'other');
        self::assertTrue($absent->isCurrent());
        $this->join('C', 'g:7');
        self::assertFalse($absent->isCurrent());
        unset($absent);
        $fresh = Hilos::$sr->groupOperationAuthority('empty', 'g', null);
        Hilos::$sr->unsubscribeFromAllGroups('empty');
        self::assertFalse($fresh->isCurrent());
        unset($fresh);
        $this->assertEmptyObservations();
    }

    /** Empty membership storage retires independently of exact, head-alias and absent-prefix operation observations. */
    public function testStructuralRetirementKeepsPreparedExactHeadAndAbsentObservations(): void
    {
        $registry = new ReflectionProperty(SignalRouter::class, 'subscriptions')->getValue(Hilos::$sr);
        $this->join('C', 'g:7');
        $this->join('C', 'g:8');
        $registry->unsubscribeFromGroup('C', 'g:7');
        $prepared = Hilos::$sr->groupOperationAuthority('C', 'g', 'g:7');
        $absent = Hilos::$sr->groupOperationAuthority('C', 'missing', null);
        $registry->unsubscribeFromGroup('C', 'g:8');
        foreach (['groups', 'groupConnectionSlots', 'groupConnections', 'groupNameIndexes', 'groupNameSlots',
            'groupNames', 'groupGenerations', 'groupRevisions'] as $property) {
            self::assertSame([], new ReflectionProperty($registry, $property)->getValue($registry), $property);
        }
        self::assertTrue(new ReflectionProperty($registry, 'groupConnectionIndex')->getValue($registry)->isEmpty());
        self::assertTrue($prepared->isCurrent());
        self::assertTrue($absent->isCurrent());
        Hilos::$sr->unsubscribeFromAllGroups('C');
        self::assertFalse($prepared->isCurrent(), 'Explicit leave_all revokes even an already empty membership set');
        self::assertFalse($absent->isCurrent());
        unset($prepared, $absent);
        $this->assertEmptyObservations();
    }

    /** Without explicit leave_all, the last observer still owns retirement of its weak metadata slot. */
    public function testStructuralRetirementReleasesItsLastWeakSlotsWithoutLeaveAll(): void
    {
        $registry = new ReflectionProperty(SignalRouter::class, 'subscriptions')->getValue(Hilos::$sr);
        $this->join('C', 'last');
        $exact = Hilos::$sr->groupOperationAuthority('C', 'g', 'g:7');
        $absent = Hilos::$sr->groupOperationAuthority('C', 'missing', null);
        $registry->unsubscribeFromGroup('C', 'last');
        self::assertTrue($exact->isCurrent());
        self::assertTrue($absent->isCurrent());
        unset($exact, $absent);
        $this->assertEmptyObservations();
    }

    /** A semantic connection-wide departure invalidates all observations before a new same-name admission. */
    public function testExplicitNonemptyLeaveAllRevokesPreparedAndAbsentObservations(): void
    {
        $registry = new ReflectionProperty(SignalRouter::class, 'subscriptions')->getValue(Hilos::$sr);
        $this->join('C', 'g:7');
        $this->join('C', 'last');
        $registry->unsubscribeFromGroup('C', 'g:7');
        $exact = Hilos::$sr->groupOperationAuthority('C', 'g', 'g:7');
        $absent = Hilos::$sr->groupOperationAuthority('C', 'missing', null);
        Hilos::$sr->unsubscribeFromAllGroups('C');
        self::assertFalse($exact->isCurrent());
        self::assertFalse($absent->isCurrent());
        $this->join('C', 'g:7');
        $replacement = Hilos::$sr->groupOperationAuthority('C', 'g', 'g:7');
        unset($exact, $absent);
        self::assertTrue($replacement->isCurrent(), 'Old observation retirement cannot delete a replacement slot');
        unset($replacement);
        $this->assertEmptyObservations();
    }

    public function testQualificationDoesNotPinAReplacedRouterOrItsRegistry(): void
    {
        $router = WeakReference::create(Hilos::$sr);
        $registry = WeakReference::create(new ReflectionProperty(SignalRouter::class, 'subscriptions')->getValue(Hilos::$sr));
        $authority = Hilos::$sr->groupOperationAuthority('C', 'g', null);
        Hilos::$sr = new SignalRouter();
        self::assertNull($router->get());
        self::assertNull($registry->get());
        self::assertFalse($authority->isCurrent());
        unset($authority);
        $this->assertEmptyObservations();
    }

    /** @return iterable<string, array{SignalDispatchPhase}> Every terminal retirement path */
    public static function terminalPhases(): iterable
    {
        yield 'complete' => [SignalDispatchPhase::Complete];
        yield 'failed' => [SignalDispatchPhase::Failed];
        yield 'canceled' => [SignalDispatchPhase::Canceled];
    }

    /** @param SignalDispatchPhase $phase Exact terminal outcome */
    #[DataProvider('terminalPhases')]
    public function testEveryTerminalOutcomeRetiresQualification(SignalDispatchPhase $phase): void
    {
        $dispatch = new SignalDispatch(null, static function () use ($phase): Generator {
            yield;
            if ($phase === SignalDispatchPhase::Failed) {
                throw new RuntimeException('delivery failed');
            }
        });
        $dispatch->groupOperation = Hilos::$sr->groupOperationAuthority('C', 'g', null);
        $authority = WeakReference::create($dispatch->groupOperation);
        if ($phase === SignalDispatchPhase::Canceled) {
            $dispatch->cancel();
        } else {
            try {
                $dispatch->runPortion(1);
                self::assertSame(SignalDispatchPhase::Complete, $phase);
            } catch (RuntimeException $failure) {
                self::assertSame(SignalDispatchPhase::Failed, $phase);
                self::assertSame('delivery failed', $failure->getMessage());
            }
        }
        self::assertSame($phase, $dispatch->phase);
        self::assertNull($dispatch->groupOperation);
        self::assertNull($authority->get());
        $this->assertEmptyObservations();
    }

    /**
     * @param string $acceptKey Admitted connection
     * @param string $group Admitted full group name
     */
    private function join(string $acceptKey, string $group): void
    {
        Hilos::$sr->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO($acceptKey, $group));
    }

    /** Every name slot dies with its last observation, including absent names and shared exact aliases. */
    private function assertEmptyObservations(): void
    {
        $registry = new ReflectionProperty(SignalRouter::class, 'subscriptions')->getValue(Hilos::$sr);
        self::assertSame([], new ReflectionProperty($registry, 'groupOperationNames')->getValue($registry));
        self::assertSame([], new ReflectionProperty($registry, 'groupOperationPrefixes')->getValue($registry));
    }
}
