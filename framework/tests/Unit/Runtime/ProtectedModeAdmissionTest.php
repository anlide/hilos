<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Exception\LogicException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WeakReference;

/** Admission preparation is process-private; compare every verdict with the canonical captured row. */
final class ProtectedModeAdmissionTest extends TestCase
{
    /** @return iterable<string, array{string}> Canonical phases and a fail-closed unknown phase */
    public static function phases(): iterable
    {
        foreach ([StateProtectedModeRuntime::PHASE_INACTIVE, StateProtectedModeRuntime::PHASE_ACTIVATING,
            StateProtectedModeRuntime::PHASE_ACTIVE, StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::PHASE_DEACTIVATING, 'unknown'] as $phase) {
            yield $phase => [$phase];
        }
    }

    #[DataProvider('phases')]
    public function testPreparedAdmissionRetainsTheCanonicalQueueTimeVerdictAcrossLaterWrites(string $phase): void
    {
        $state = StateProtectedModeRuntime::create();
        $state->phase = $phase;
        $state->initiatorAcceptKey = 'operator-key';
        $state->initiatorSessionTokenHash = 'operator';
        $state->admittedSessionTokenHashes = ['pass', '0', '', 'pass'];
        $state->circleSessionTokenHashes = ['circle', '07', '', 'circle'];
        $original = StateProtectedModeRuntime::fromRow($state->toArray());
        $plan = new ProtectedModeRuntime($state)->admissionPlan();
        $state->phase = StateProtectedModeRuntime::PHASE_INACTIVE;
        $state->initiatorAcceptKey = 'changed-key';
        $state->initiatorSessionTokenHash = 'changed-session';
        $state->admittedSessionTokenHashes = ['later-pass'];
        $state->circleSessionTokenHashes = ['later-circle'];
        foreach ($plan->build() as $_) {
            self::assertFalse($plan->ready);
        }
        self::assertTrue($plan->ready);
        $policy = new ReflectionProperty($plan, 'policy')->getValue($plan);
        foreach ([null, 'operator-key', 'stranger-key'] as $key) {
            foreach ([null, 'operator', 'pass', 'circle', '0', '07', '7', '', 'stranger', 'later-pass', 'later-circle'] as $hash) {
                self::assertSame($original->locksOut($key, $hash), $plan->locksOut($key, $hash));
                self::assertLessThanOrEqual(1, count($policy->admittedSessionTokenHashes));
                self::assertLessThanOrEqual(1, count($policy->circleSessionTokenHashes));
            }
        }
        self::assertSame([], iterator_to_array($plan->build()), 'Completed preparation is reused');
        self::assertSame(['later-pass'], $state->admittedSessionTokenHashes);
        self::assertSame(['later-circle'], $state->circleSessionTokenHashes);
    }

    public function testLargeAdmissionsChargeOneHashPerStepBeforeAnyRecipientCanBeJudged(): void
    {
        $state = StateProtectedModeRuntime::create();
        $state->phase = StateProtectedModeRuntime::PHASE_VERIFYING;
        $admittedHashes = [];
        $circleHashes = [];
        for ($index = 0; $index < 4096; $index++) {
            $admittedHashes[] = 'pass-' . $index;
            $circleHashes[] = 'circle-' . $index;
        }
        $state->admittedSessionTokenHashes = $admittedHashes;
        $state->circleSessionTokenHashes = $circleHashes;
        $plan = new ProtectedModeRuntime($state)->admissionPlan();
        $admitted = new ReflectionProperty($plan, 'admittedByHash');
        $circle = new ReflectionProperty($plan, 'circleByHash');
        $previous = 0;
        $steps = 0;
        foreach ($plan->build() as $_) {
            $count = count($admitted->getValue($plan)) + count($circle->getValue($plan));
            self::assertLessThanOrEqual(1, $count - $previous);
            self::assertFalse($plan->ready);
            $previous = $count;
            $steps++;
        }
        self::assertSame(8192, $previous);
        self::assertGreaterThanOrEqual(8192, $steps);
        self::assertFalse($plan->locksOut('key', 'pass-4095'));
        self::assertFalse($plan->locksOut('key', 'circle-4095'));
        self::assertTrue($plan->locksOut('key', 'stranger'));
    }

    public function testThePlanDoesNotRetainItsLiveStateOrViewWithoutGc(): void
    {
        $state = StateProtectedModeRuntime::create();
        $state->phase = StateProtectedModeRuntime::PHASE_VERIFYING;
        $state->admittedSessionTokenHashes = ['pass'];
        $view = new ProtectedModeRuntime($state);
        $weakState = WeakReference::create($state);
        $weakView = WeakReference::create($view);
        $plan = $view->admissionPlan();
        $work = $plan->build();
        $work->rewind();
        unset($view, $state);
        self::assertNull($weakView->get());
        self::assertNull($weakState->get());
        while ($work->valid()) {
            $work->next();
        }
        self::assertFalse($plan->locksOut(null, 'pass'));
    }

    public function testAnUnpreparedRuleRefusesThePrematureVisit(): void
    {
        $plan = new ProtectedModeRuntime(StateProtectedModeRuntime::create())->admissionPlan();
        $this->expectException(LogicException::class);
        $plan->locksOut(null, null);
    }
}
