<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\SignalDispatch;
use Hilos\TruthSource\RtNodeSourceMap;
use Hilos\TruthSource\RtSourceReport;
use Hilos\TruthSource\RtSourceReportNode;
use Hilos\TruthSource\RtSourceReports;
use PHPUnit\Framework\TestCase;
use WeakReference;

/** Exact finite claim values and portioned folds without retaining the mutable report map. */
final class RtSourceReportsTest extends TestCase
{
    public function testSnapshotsKeepOriginalValuesAndOrderAcrossOverwriteReleaseAndRejoin(): void
    {
        $map = new RtNodeSourceMap();
        $map->note('first', ['rows'], [], ['rows' => ['old']]);
        $map->note('second', ['rows'], [], ['rows' => ['second']]);
        $original = $map->sourceReports();
        $map->note('first', ['rows'], [], ['rows' => ['replacement']]);
        $overwritten = $map->sourceReports();
        $map->release('first');
        $map->note('third', ['rows'], [], ['rows' => ['third']]);
        $map->note('first', ['rows'], [], ['rows' => ['rejoined']]);

        $this->assertSame(['first', 'second'], array_map(static fn(RtSourceReport $report): string => $report->agentId,
            array_values(iterator_to_array($original->reports()))));
        $this->assertSame(['first', 'second'], array_map(static fn(RtSourceReport $report): string => $report->agentId,
            array_values(iterator_to_array($overwritten->reports()))));
        $this->assertSame(['second', 'third', 'first'], array_map(static fn(RtSourceReport $report): string => $report->agentId,
            array_values(iterator_to_array($map->sourceReports()->reports()))));
        $oldPlan = $original->plan();
        foreach ($oldPlan->build() as $_) {
        }
        $newPlan = $map->sourceReports()->plan();
        foreach ($newPlan->build() as $_) {
        }
        $this->assertSame(['rows' => ['old', 'second']], $oldPlan->keyScopes);
        $this->assertSame(['rows' => ['second', 'third', 'rejoined']], $newPlan->keyScopes);
    }

    public function testFoldPreservesWholeKeySetPartialEmptyUndeclaredAndNumericSemantics(): void
    {
        $map = new RtNodeSourceMap();
        $map->note('early_partial', ['whole', 'partial'], ['whole', 'partial']);
        $map->note('keys', ['keyed', 'empty', '0', '07'], [], [
            'empty' => [], 'keyed' => ['07', '7', '07'], 'unclaimed' => ['ignored'],
            '0' => ['zero'], '07' => ['leading'],
        ]);
        $map->note('set_first', ['sets'], [], ['sets' => []], ['sets' => '42']);
        $map->note('partial_set', ['sets'], ['sets'], ['sets' => []], ['sets' => 'partial']);
        $map->note('set_second', ['sets'], [], ['sets' => []], ['sets' => '7']);
        $map->note('full', ['whole']);
        $plan = $map->sourceReports()->plan();
        foreach ($plan->build() as $_) {
        }

        $this->assertSame(['whole'], $plan->fullyOwnedCollections);
        $this->assertSame(['empty' => [], 'keyed' => ['07', '7'], '07' => ['leading']], $plan->keyScopes);
        $this->assertSame(['sets' => ['42', '7']], $plan->setScopes);
        $this->assertSame(['whole' => true, 'partial' => true], $plan->wholeClaims);
        $this->assertSame(['keyed' => ['07' => true, '7' => true], '0' => ['zero' => true], '07' => ['leading' => true]], $plan->rowClaims);
        $this->assertSame(['sets' => ['42' => true, 'partial' => true, '7' => true]], $plan->setClaims);
        $this->assertSame($map->fullyOwnedCollections(), $plan->fullyOwnedCollections);
        $this->assertSame($map->keyScopedCollections(), $plan->keyScopes);
        $this->assertSame($map->setScopedCollections(), $plan->setScopes);
        $this->assertEquals($map->claims(), array_values(iterator_to_array($plan->claimEntries())));
    }

    public function testAKeyVisitChargesOneStepAndOnlyTheCompletedFoldIsReused(): void
    {
        $map = new RtNodeSourceMap();
        $keys = array_map(strval(...), range(0, 4095));
        $map->note('owner', ['rows'], [], ['rows' => $keys]);
        $snapshot = $map->sourceReports();
        $plan = $snapshot->plan();
        $dispatch = new SignalDispatch(null, static fn() => $plan->build());
        $dispatch->runPortion(64);
        $this->assertFalse($plan->ready);
        $this->assertArrayNotHasKey('4095', $plan->rowClaims['rows']);
        $this->assertLessThanOrEqual(64, count($plan->rowClaims['rows']));
        for ($portion = 0; $portion < 65; $portion++) {
            $dispatch->runPortion(64);
        }
        $this->assertFalse($dispatch->hasWork());
        $this->assertTrue($plan->ready);
        $this->assertSame(['rows' => $keys], $plan->keyScopes);
        $this->assertSame($plan, $snapshot->plan());
        $this->assertSame([], iterator_to_array($plan->build()));
        $map->note('owner', ['rows'], [], ['rows' => ['next']]);
        $this->assertNotSame($plan, $map->sourceReports()->plan());
    }

    public function testAnAbandonedFoldDoesNotPoisonTheSameOriginalReportSet(): void
    {
        $map = new RtNodeSourceMap();
        $map->note('owner', ['rows'], [], ['rows' => array_map(strval(...), range(0, 129))]);
        $snapshot = $map->sourceReports();
        $abandoned = $snapshot->plan();
        $dispatch = new SignalDispatch(null, static fn() => $abandoned->build());
        $dispatch->runPortion(16);
        $dispatch->cancel();
        $fresh = $snapshot->plan();
        $this->assertNotSame($abandoned, $fresh);
        foreach ($fresh->build() as $_) {
        }
        $this->assertTrue($fresh->ready);
        $this->assertSame(['rows' => array_map(strval(...), range(0, 129))], $fresh->keyScopes);
        $this->assertSame($fresh, $snapshot->plan());
    }

    public function testOriginalReportsRetainNoCanonicalOwnerAndRetireWithoutGc(): void
    {
        $map = new RtNodeSourceMap();
        $map->note('owner', ['rows'], [], ['rows' => ['original']]);
        $snapshot = $map->sourceReports();
        $weakMap = WeakReference::create($map);
        $weakReport = WeakReference::create(iterator_to_array($snapshot->reports())[1]);
        $map->release('owner');
        unset($map);
        $this->assertNull($weakMap->get());
        $this->assertNotNull($weakReport->get());
        $plan = $snapshot->plan();
        foreach ($plan->build() as $_) {
        }
        unset($snapshot, $plan);
        $this->assertNull($weakReport->get());
    }

    public function testPersistentTreeStaysBalancedWhileOldReadersKeepTheirOriginalCohort(): void
    {
        $root = null;
        for ($position = 1; $position <= 4096; $position++) {
            $root = RtSourceReportNode::put($root, $position,
                new RtSourceReport('owner_' . $position, ['rows'], [], ['rows' => [(string)$position]], []));
        }
        $original = new RtSourceReports($root);
        $this->assertLessThanOrEqual(14, $root->height);
        for ($position = 1; $position <= 4096; $position += 2) {
            $root = RtSourceReportNode::remove($root, $position);
        }
        $root = RtSourceReportNode::put($root, 2, new RtSourceReport('replacement', ['rows'], [], ['rows' => ['new']], []));
        $this->assertLessThanOrEqual(14, $root->height);
        $this->assertSame(range(1, 4096), array_keys(iterator_to_array($original->reports())));
        $current = iterator_to_array(new RtSourceReports($root)->reports());
        $this->assertSame(range(2, 4096, 2), array_keys($current));
        $this->assertSame('replacement', $current[2]->agentId);
        $this->assertSame('owner_2', iterator_to_array($original->reports())[2]->agentId);
    }

    public function testPlansKeepLegacyScopeAndClaimOutcomesThroughDeterministicReportChurn(): void
    {
        $map = new RtNodeSourceMap();
        $history = [];
        for ($step = 0; $step < 256; $step++) {
            $agent = 'owner_' . (($step * 17) % 37);
            $collection = 'rows_' . (($step * 11) % 5);
            if ($step % 7 === 0) {
                $map->release($agent);
            } else {
                switch ($step % 6) {
                    case 0:
                        $map->note($agent, [$collection, 'shared', $collection]);
                        break;
                    case 1:
                        $map->note($agent, [$collection, 'shared'], ['shared'], [
                            'shared' => ['partial'], $collection => ['07', (string)$step, '07'], 'undeclared' => ['ignored'],
                        ]);
                        break;
                    case 2:
                        $map->note($agent, [$collection, 'shared'], [], [$collection => [], 'shared' => []],
                            ['shared' => (string)($step % 3), $collection => '07']);
                        break;
                    case 3:
                        $map->note($agent, ['0', '07', $collection], [], [
                            '07' => ['kept'], '0' => ['numeric-map-key'], $collection => [],
                        ]);
                        break;
                    case 4:
                        $map->note($agent, [$collection, 'shared'], [$collection], [$collection => ['partial']],
                            [$collection => 'partial-set']);
                        break;
                    case 5:
                        $map->note($agent, [$collection, 'shared'], [], ['shared' => ['7', '07'], $collection => ['0']]);
                        break;
                }
            }
            $snapshot = $map->sourceReports();
            $expected = [$map->fullyOwnedCollections(), $map->keyScopedCollections(), $map->setScopedCollections(), $map->claims()];
            $plan = $snapshot->plan();
            $alreadyReady = $plan->ready;
            $dispatch = new SignalDispatch(null, static fn() => $plan->build());
            $dispatch->runPortion(1);
            $this->assertSame($alreadyReady || $expected[3] === [], $plan->ready, 'Only an empty or cached fold completes in the first step');
            while ($dispatch->hasWork()) {
                $dispatch->runPortion(16);
            }
            $this->assertSame($expected[0], $plan->fullyOwnedCollections, 'Full scopes at step ' . $step);
            $this->assertSame($expected[1], $plan->keyScopes, 'Key scopes at step ' . $step);
            $this->assertSame($expected[2], $plan->setScopes, 'Set scopes at step ' . $step);
            $this->assertEquals($expected[3], array_values(iterator_to_array($plan->claimEntries())), 'Raw claims at step ' . $step);
            if ($step % 17 === 0) {
                $history[] = [$snapshot, $expected];
            }
        }
        foreach ($history as [$snapshot, $expected]) {
            $plan = $snapshot->plan();
            $this->assertTrue($plan->ready);
            $this->assertSame($expected[0], $plan->fullyOwnedCollections);
            $this->assertSame($expected[1], $plan->keyScopes);
            $this->assertSame($expected[2], $plan->setScopes);
            $this->assertEquals($expected[3], array_values(iterator_to_array($plan->claimEntries())));
        }
    }
}
