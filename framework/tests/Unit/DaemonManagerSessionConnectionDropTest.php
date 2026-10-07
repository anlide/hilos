<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClientMesh;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * A rotated session drops its local sockets and asks every other master for the rest.
 *
 * The local close path itself is covered by test:connection:drop; this seam pins which
 * keys are broadcast and that a failure on one key cannot strand the following keys.
 */
final class DaemonManagerSessionConnectionDropTest extends TestCase
{
    public function testLocalKeysAreClosedAndOnlyMissingKeysAreBroadcastOnce(): void
    {
        $daemon = new SessionConnectionDropTestManager(['ak-local-1', 'ak-local-2']);
        $daemon->mesh = new SessionConnectionDropTestMesh();

        $daemon->dropSessionConnections(['ak-local-1', 'ak-remote', 'ak-local-2']);

        $this->assertSame(['ak-local-1', 'ak-local-2'], $daemon->closed);
        $this->assertSame([['ak-remote']], $daemon->mesh->drops);
    }

    public function testAllLocalKeysNeedNoPeerFrame(): void
    {
        $daemon = new SessionConnectionDropTestManager(['ak-local']);
        $daemon->mesh = new SessionConnectionDropTestMesh();

        $daemon->dropSessionConnections(['ak-local']);

        $this->assertSame(['ak-local'], $daemon->closed);
        $this->assertSame([], $daemon->mesh->drops);
    }

    public function testWithoutAMeshTheLocalCloseStillHappens(): void
    {
        $daemon = new SessionConnectionDropTestManager(['ak-local']);

        $daemon->dropSessionConnections(['ak-local', 'ak-remote']);

        $this->assertSame(['ak-local'], $daemon->closed);
    }

    public function testAReceivedDropClosesOnlyKeysHeldHereWithoutForwarding(): void
    {
        $daemon = new SessionConnectionDropTestManager(['ak-local']);
        $daemon->mesh = new SessionConnectionDropTestMesh();
        $logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-session-drop-');
        Logger::setLogFile($logFile);

        try {
            $daemon->dropConnectionsForNode('node-b', ['ak-remote', 'ak-local']);

            $this->assertSame(['ak-local'], $daemon->closed);
            $this->assertSame([], $daemon->mesh->drops);
            $this->assertStringContainsString(
                'Dropped 1 connection(s) of a rotated session at the request of node node-b',
                (string)file_get_contents($logFile),
            );
        } finally {
            Logger::resetLogFile();
            unlink($logFile);
        }
    }

    public function testAReceivedDropWithNoLocalKeysEmitsNoInfoLine(): void
    {
        $daemon = new SessionConnectionDropTestManager([]);
        $logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-session-drop-');
        Logger::setLogFile($logFile);

        try {
            $daemon->dropConnectionsForNode('node-b', ['ak-remote']);

            $this->assertSame([], $daemon->closed);
            $this->assertSame('', (string)file_get_contents($logFile));
        } finally {
            Logger::resetLogFile();
            unlink($logFile);
        }
    }

    public function testOneFailedCloseDoesNotPreventTheNextOne(): void
    {
        $daemon = new SessionConnectionDropTestManager(['ak-fails', 'ak-next']);
        $daemon->failedKey = 'ak-fails';
        $daemon->mesh = new SessionConnectionDropTestMesh();

        $daemon->dropSessionConnections(['ak-fails', 'ak-next', 'ak-remote']);

        $this->assertSame(['ak-next'], $daemon->closed);
        $this->assertSame([['ak-remote']], $daemon->mesh->drops);
    }
}

/**
 * Records the daemon's socket-close and peer-send decisions without live sockets.
 */
final class SessionConnectionDropTestManager extends DaemonManager
{
    /** @var list<string> Keys this master holds */
    private array $localKeys;

    /** @var list<string> Keys closed here */
    public array $closed = [];

    /** @var ?string Key whose close fails */
    public ?string $failedKey = null;

    /** @var ?SessionConnectionDropTestMesh Peer mesh, absent off-cluster */
    public ?SessionConnectionDropTestMesh $mesh = null;

    /**
     * @param list<string> $localKeys Keys this master holds
     */
    public function __construct(array $localKeys)
    {
        parent::__construct();
        $this->localKeys = $localKeys;
    }

    /**
     * @param string $acceptKey Connection key to close
     * @return bool Whether this master held and closed the key
     * @throws SocketException When the nominated key's close fails
     */
    public function dropWebSocketConnection(string $acceptKey): bool
    {
        if ($acceptKey === $this->failedKey) {
            throw new SocketException('test close failure');
        }
        if (!in_array($acceptKey, $this->localKeys, true)) {
            return false;
        }

        $this->closed[] = $acceptKey;
        $this->localKeys = array_values(array_diff($this->localKeys, [$acceptKey]));

        return true;
    }

    /**
     * @param ?ClientMesh $mesh Peer server passed by the daemon
     * @param list<string> $acceptKeys Keys not held here
     */
    protected function dropConnectionsOnPeers(?ClientMesh $mesh, array $acceptKeys): void
    {
        parent::dropConnectionsOnPeers($this->mesh ?? $mesh, $acceptKeys);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new SessionConnectionDropTestAgentManagerDaemon();
    }
}

/**
 * The drop tests never start an agent.
 */
final class SessionConnectionDropTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type to start
     * @param ?string $agentIndex Agent index to start
     * @return AgentDaemonInterface Never returned
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('the drop test starts no agent');
    }
}

/**
 * Records sibling-drop broadcasts while the daemon sees a peer mesh.
 */
final class SessionConnectionDropTestMesh implements ClientMesh
{
    /** @var list<list<string>> Broadcast connection-drop batches */
    public array $drops = [];

    /**
     * @param string $nodeId Target node
     * @param string $acceptKey Target connection
     * @param SignalDTO $signal Signal to send
     * @return bool Always true in this fake
     */
    public function sendSignalToClientNode(string $nodeId, string $acceptKey, SignalDTO $signal): bool
    {
        return true;
    }

    /**
     * @param SignalDTO $signal Signal to fan out
     */
    public function broadcastClientFanout(SignalDTO $signal): void
    {
    }

    /**
     * @param list<string> $acceptKeys Sibling keys to close
     */
    public function broadcastConnectionDrop(array $acceptKeys): void
    {
        $this->drops[] = $acceptKeys;
    }

    /**
     * @param SignalDTO $signal Page access re-decision announcement
     */
    public function broadcastPageAccessReassess(SignalDTO $signal): void
    {
    }

    /**
     * @param list<string> $acceptKeys Connections leaving every group
     */
    public function broadcastGroupLeaveAll(array $acceptKeys): void
    {
    }

    /**
     * @param string $nodeId Target node
     * @param list<string> $acceptKeys Connection snapshot
     */
    public function sendConnectionsSnapshotToNode(string $nodeId, array $acceptKeys): void
    {
    }

    /**
     * @param list<string> $opened Opened keys
     * @param list<string> $closed Closed keys
     */
    public function broadcastConnectionsDelta(array $opened, array $closed): void
    {
    }
}
