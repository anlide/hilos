<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Cluster\Probe\RtSetProbeAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosProbeNote;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The set probe's delete command uses the same item action and set ownership guard as a real
 * runtime deletion. The cluster scenario drives this route across two nodes.
 */
final class RtSetProbeDeleteTest extends TestCase
{
    private const string OWN_AGENT = 'probe-own';
    private const string OTHER_AGENT = 'probe-other';

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRuntime = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRuntime = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new class extends RtContext {
            public function configure(): void
            {
            }
        };
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::register(HilosProbeNote::RT_COLLECTION, TruthSourceKeys::set('node-a'), self::OWN_AGENT);
        RtTruthSourceRegistry::register(HilosProbeNote::RT_COLLECTION, TruthSourceKeys::set('node-b'), self::OTHER_AGENT);
        ExecutionContext::setCurrentAgentId(self::OWN_AGENT);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterAgent(self::OWN_AGENT);
        RtTruthSourceRegistry::unregisterAgent(self::OTHER_AGENT);
        ExecutionContext::setCurrentAgentId(null);
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$rt = $this->previousRuntime;

        parent::tearDown();
    }

    public function testDeletesANoteInItsOwnSet(): void
    {
        Hilos::$rt?->hilosProbeNotes->actions->write('note-1', 'node-a', 'text');

        $reply = $this->erase('note-1');

        $this->assertTrue($reply->isOk());
        $this->assertSame('note-1', $reply->payload[CommandConstants::FIELD_RT_STATE_ID]);
        // This isolated test has no worker subscriber to evict the cached view after removal.
        Hilos::$rt?->hilosProbeNotes->clearCache();
        $this->assertNull(Hilos::$rt?->hilosProbeNotes['note-1']);
    }

    public function testReportsANoteMissingOnThisNode(): void
    {
        $reply = $this->erase('missing');

        $this->assertFalse($reply->isOk());
        $this->assertSame("No note 'missing' on this node", $reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    public function testRefusesToDeleteAnotherNodesSet(): void
    {
        ExecutionContext::setCurrentAgentId(self::OTHER_AGENT);
        Hilos::$rt?->hilosProbeNotes->actions->write('foreign', 'node-b', 'text');
        ExecutionContext::setCurrentAgentId(self::OWN_AGENT);

        $reply = $this->erase('foreign');

        $this->assertFalse($reply->isOk());
        $this->assertStringContainsString("it holds set 'node-a'", $reply->payload[CommandConstants::FIELD_MESSAGE]);
        $this->assertNotNull(Hilos::$rt?->hilosProbeNotes['foreign']);
    }

    public function testRejectsAMissingRowId(): void
    {
        $reply = $this->erase('');

        $this->assertFalse($reply->isOk());
        $this->assertSame('Missing ' . CommandConstants::FIELD_RT_STATE_ID, $reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    private function erase(string $noteId): CommandReplyDTO
    {
        $agent = new RtSetProbeAgent();
        $agent->onSignalCommand(
            new CommandRequestDTO('corr-1', CliCommands::CLUSTER_TEST_RT_DELETE, [
                CommandConstants::FIELD_RT_STATE_ID => $noteId,
            ]),
            '',
            '',
        );

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(CommandReplyDTO::class, $signal->data);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        return $signal->data;
    }
}
