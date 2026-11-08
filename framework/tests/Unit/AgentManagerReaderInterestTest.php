<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Source\SourceChange;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use PHPUnit\Framework\TestCase;
use WeakReference;

/** The unannounced pair and the sub-pass construction window never leave this process. */
final class AgentManagerReaderInterestTest extends TestCase
{
    public function testBoundReportsRetainTheirOriginalPairsWithoutPublishingPartialOrLaterUnions(): void
    {
        $manager = new ReaderInterestAgentManager();
        self::assertFalse($manager->hasChangedReaderInterest());
        $manager->handleSourceInterest(new WorkerSourceInterestDTO(['old'], ['old-db']), 3);
        self::assertTrue($manager->hasChangedReaderInterest());
        self::assertTrue($manager->hasChangedReaderInterest(), 'Readiness does not consume the pair');
        self::assertSame([SourceChange::KIND_RT => ['old'], SourceChange::KIND_DB => ['old-db']],
            $manager->consumeChangedReaderInterest());
        self::assertFalse($manager->hasChangedReaderInterest());
        $queue = [];
        $manager->setReaderInterestDelivery(static function (Generator $delivery) use (&$queue): void { $queue[] = $delivery; });
        $runtime = [];
        $database = [];
        for ($index = 0; $index < 8192; $index++) {
            $runtime[] = 'rt-' . $index;
            $database[] = 'db-' . $index;
        }
        self::assertSame([SourceChange::KIND_RT => $runtime, SourceChange::KIND_DB => $database],
            $manager->handleSourceInterest(new WorkerSourceInterestDTO($runtime, $database), 3));
        self::assertCount(1, $queue);
        self::assertFalse($manager->hasChangedReaderInterest(), 'Queued preparation is owned by the existing source FIFO');
        self::assertNull($manager->consumeChangedReaderInterest(), 'Enqueue cannot construct or publish the whole AFTER union');
        $queue[0]->rewind();
        for ($step = 0; $step < 64; $step++) {
            $queue[0]->next();
        }
        self::assertTrue($queue[0]->valid(), 'One master portion cannot finish the original large pair');
        self::assertNull($manager->consumeChangedReaderInterest(), 'A prefix of one kind is not an announced union');
        $manager->handleSourceInterest(new WorkerSourceInterestDTO(['future'], ['future-db']), 3);
        self::assertCount(2, $queue);
        while ($queue[0]->valid()) {
            $queue[0]->next();
        }
        self::assertSame([SourceChange::KIND_RT => $runtime, SourceChange::KIND_DB => $database],
            $manager->consumeChangedReaderInterest(), 'The first completed transition cannot read the later mutable map');
        foreach ($queue[1] as $_) {
        }
        self::assertSame([SourceChange::KIND_RT => ['future'], SourceChange::KIND_DB => ['future-db']],
            $manager->consumeChangedReaderInterest());
        $manager->handleSourceInterest(new WorkerSourceInterestDTO(['future'], ['future-db']), 4);
        foreach ($queue[2] as $_) {
        }
        self::assertNull($manager->consumeChangedReaderInterest(), 'A second holder of the same ordered union is still silent');
        $manager->releaseReaderInterestOfWorker(3);
        foreach ($queue[3] as $_) {
        }
        self::assertNull($manager->consumeChangedReaderInterest());
        $manager->releaseReaderInterestOfWorker(4);
        foreach ($queue[4] as $_) {
        }
        self::assertTrue($manager->hasChangedReaderInterest(), 'A complete empty pair is real work');
        self::assertSame([SourceChange::KIND_RT => [], SourceChange::KIND_DB => []], $manager->consumeChangedReaderInterest(),
            'The last departure publishes the complete empty pair once');
        self::assertNull($manager->consumeChangedReaderInterest());
    }

    public function testSuspendedUnionDoesNotPinItsReportOwnerAndCannotPublishAfterItLeaves(): void
    {
        $manager = new ReaderInterestAgentManager();
        $queue = [];
        $manager->setReaderInterestDelivery(static function (Generator $delivery) use (&$queue): void { $queue[] = $delivery; });
        $manager->handleSourceInterest(new WorkerSourceInterestDTO(array_fill(0, 8192, 'duplicate'), ['db']), 3);
        $queue[0]->rewind();
        $queue[0]->next();
        $queue[0]->next();
        $owner = WeakReference::create($manager);
        unset($manager);
        self::assertNull($owner->get(), 'The original plans and queued cursor hold no mutable manager');
        $queue[0]->next();
        self::assertFalse($queue[0]->valid(), 'An expired report owner stops its private preparation on the next visit');
    }

    public function testAnOldBindingCannotRemoveItsReplacementAndUnbindingKeepsTheConsumingAdapter(): void
    {
        $manager = new ReaderInterestAgentManager();
        $first = [];
        $second = [];
        $old = static function (Generator $delivery) use (&$first): void { $first[] = $delivery; };
        $current = static function (Generator $delivery) use (&$second): void { $second[] = $delivery; };
        $manager->setReaderInterestDelivery($old);
        $manager->setReaderInterestDelivery($current);
        $manager->removeReaderInterestDelivery($old);
        $manager->handleSourceInterest(new WorkerSourceInterestDTO(['0', '7'], ['db']), 3);
        self::assertSame([], $first);
        self::assertCount(1, $second);
        foreach ($second[0] as $_) {
        }
        self::assertSame([SourceChange::KIND_RT => ['0', '7'], SourceChange::KIND_DB => ['db']],
            $manager->consumeChangedReaderInterest());
        $manager->removeReaderInterestDelivery($current);
        $manager->handleSourceInterest(new WorkerSourceInterestDTO(['7', '0'], []), 3);
        self::assertCount(1, $second);
        self::assertSame([SourceChange::KIND_RT => ['7', '0'], SourceChange::KIND_DB => []],
            $manager->consumeChangedReaderInterest(), 'The consuming adapter retains the exact ordered pair');
    }
}

/** Reader reports need no agent or process; factory use would be a fixture mistake. */
final class ReaderInterestAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused instance key
     * @return AgentDaemonInterface No agent is created by these reader-report cases
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('Reader interest fixture starts no agents');
    }
}
