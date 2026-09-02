<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Sync\DTO\DbReHydrateSignalData;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent-facing half of the re-hydrate announcement (HIL-479).
 *
 * An agent that has just replaced the database under the node cannot reach the other processes
 * itself; it queues a worker-drained signal its own daemon turns into a frame. These cases pin
 * what it queues - the signal type the daemon and the workers already dispatch on, and a payload
 * that names nothing but the announcer, because the event names no collection and no row.
 *
 * The announcer is not decoration (HIL-436): the daemon opens a barrier over the swap and has to
 * send its verdict back to exactly the agent that is waiting for it.
 */
final class DbReHydrateTriggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testTheAnnouncementIsQueuedAsADbReHydrateSignalNamingItsAnnouncer(): void
    {
        new DbReHydrateTriggerTestAgent()->announce();

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal, 'The announcement queues one worker-drained signal');
        $this->assertSame(SignalTypeConstants::DB_REHYDRATE, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::DB_REHYDRATE, $signal->signalName->getName());
        $this->assertInstanceOf(DbReHydrateSignalData::class, $signal->data);
        $this->assertSame(
            [
                AgentConstants::FIELD_AGENT_ID => 'db-swapper',
                DbReHydrateSignalData::FIELD_REPLY_TO_NODE_ID => null,
                DbReHydrateSignalData::FIELD_REPLY_TO_ROUND => null,
            ],
            $signal->data->toArray(),
            'The swap names its announcer and nothing else - no collection and no row; an agent'
            . ' announcing here answers to nobody else, so there is neither a node to reply to nor'
            . ' a round of its own to answer under',
        );
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'Exactly one signal is queued');
    }

    public function testTheAnnouncementNamesTheAgentThatNoticedTheSwap(): void
    {
        new DbReHydrateTriggerTestAgent()->announce();

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalSource::AGENT, $signal->signalSource->getSource());
        $this->assertSame('db-swapper', $signal->signalSource->getType());
    }
}

/**
 * A minimal agent that exposes the protected re-hydrate trigger for the test.
 */
final class DbReHydrateTriggerTestAgent extends AbstractAgent
{
    /** @var string Agent type identifier */
    public const string AGENT_TYPE = 'db-swapper';

    public function onStop(): void
    {
    }

    /**
     * Announces that the database under this node was replaced.
     */
    public function announce(): void
    {
        $this->requestDbReHydrate();
    }
}
