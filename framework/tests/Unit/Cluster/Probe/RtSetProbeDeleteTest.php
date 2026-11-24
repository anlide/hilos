<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Cluster\Probe\RtSetProbeAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
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
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::OWN_AGENT));
        SourceInterestRegistry::readsWhatItMounts();
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

    public function testWritingNeedsProofOfAbsenceButCanRewriteAnOwnedRowBeforeTheSnapshot(): void
    {
        Hilos::$rt->hilosProbeNotes->actions->write('own', 'node-a', 'local');
        SourceInterestRegistry::register(SourceChange::KIND_RT, HilosProbeNote::RT_COLLECTION, SourceConsumer::agent(self::OWN_AGENT));
        SourceInterestRegistry::readsWhatIsDelivered();

        $this->assertTrue($this->write('own', 'node-a', 'updated')->isOk());
        $this->assertSame('updated', Hilos::$rt->hilosProbeNotes['own']?->text);
        $this->assertTrue($this->erase('own')->isOk());
        $this->assertFalse($this->write('shared-id', 'node-a', 'local')->isOk());

        SourceInterestRegistry::markReady(SourceChange::KIND_RT, HilosProbeNote::RT_COLLECTION);
        ExecutionContext::setCurrentAgentId(self::OTHER_AGENT);
        $this->assertTrue($this->write('shared-id', 'node-b', 'remote')->isOk());
        ExecutionContext::setCurrentAgentId(self::OWN_AGENT);
        $this->assertFalse($this->write('shared-id', 'node-a', 'would replace remote')->isOk());
        $this->assertSame('remote', Hilos::$rt->hilosProbeNotes['shared-id']?->text);
        $this->assertTrue($this->write('new', 'node-a', 'created after publication')->isOk());
    }

    private function erase(string $noteId): CommandReplyDTO
    {
        return $this->command(CliCommands::CLUSTER_TEST_RT_DELETE, [CommandConstants::FIELD_RT_STATE_ID => $noteId]);
    }

    private function write(string $noteId, string $setKey, string $text): CommandReplyDTO
    {
        return $this->command(CliCommands::CLUSTER_TEST_RT_WRITE, [
            CommandConstants::FIELD_RT_STATE_ID => $noteId,
            CommandConstants::FIELD_RT_SET_KEY => $setKey,
            CommandConstants::FIELD_TEXT => $text,
        ]);
    }

    /** @param array<string, string> $payload */
    private function command(string $name, array $payload): CommandReplyDTO
    {
        $agent = new RtSetProbeAgent();
        $agent->onSignalCommand(new CommandRequestDTO('corr-1', $name, $payload), '', '');

        $replies = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        $this->assertCount(1, $replies);

        return $replies[0];
    }
}
