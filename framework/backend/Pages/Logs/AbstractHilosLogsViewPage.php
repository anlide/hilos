<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\Log\DTO\LogsFollowStartSignalData;
use Hilos\Log\DTO\LogsFollowStopSignalData;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\DTO\LogsFollowStartActionDTO;
use Hilos\Pages\Logs\DTO\LogsFollowStopActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;

/**
 * AbstractHilosLogsViewPage - Abstract base for Hilos logs viewer page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Logs\LogsViewPage).
 *
 * The page does not read a log file and never could: the file belongs to one node's
 * {@see LogStoreAgent}, and this page runs wherever the browser happens to be attached. It
 * checks that the node the request names still exists, hands the request to that node's owner
 * and steps out of its own action - the owner answers the browser directly (HIL-757).
 *
 * Following a live file is the same arrangement carried on in time (HIL-389): the start is a read
 * that leaves the owner reading, and from then on the owner sends the file's growth to the socket
 * itself. All the page keeps of it is which node each connection is following, because that is the
 * one thing the owner cannot be asked for later - a viewer that switches nodes, or leaves without
 * a word, must release the reader it left behind.
 */
abstract class AbstractHilosLogsViewPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_VIEW;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW,
    ];

    public const array ACTIONS = [
        HilosSignalConstants::LOGS_READ_LINES => LogsReadLinesActionDTO::class,
        HilosSignalConstants::LOGS_FOLLOW_START => LogsFollowStartActionDTO::class,
        HilosSignalConstants::LOGS_FOLLOW_STOP => LogsFollowStopActionDTO::class,
    ];

    /**
     * @var array<string, string> Accept key → id of the node whose owner is following for it
     *
     * One entry per following connection, and one node per entry: a viewer has one screen, so a
     * change of stream, level or node is a follow REPLACING this one rather than a second beside
     * it. Static for the reason the overview page's subscriber set is
     * ({@see AbstractHilosLogsPage::$logsOverviewSubscribers}): the set belongs to the worker, not
     * to any one dispatch of the page.
     */
    private static array $followNodeByAcceptKey = [];

    /**
     * Forwards one read or one follow to the node that owns the file.
     *
     * The two starts hand over and step out; the removal is answered here and now, because it
     * cannot fail in a way the viewer could act on and waiting for the owner to confirm would hold
     * a browser in loading for a fact about somebody else.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @return ?ActionReplyDTO Always null: a read and a follow are owed by the owner, a removal owes nothing
     * @throws AgentUnknownActionException When the page does not support the action
     * @throws InvalidActionPayloadException When the payload is not the request its action declares
     * @throws TableActionException When the request names a node this cluster does not have, or one
     *     the master last saw offline
     * @throws RtActionsStateCollectionNullException When the cluster roster is unavailable
     * @throws InvalidArgumentException When the frame to the owner cannot be named
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::LOGS_READ_LINES:
                if (!$dto instanceof LogsReadLinesActionDTO) {
                    throw new InvalidActionPayloadException($action, LogsReadLinesActionDTO::class, $dto);
                }

                return $this->forwardRead($acceptKey, $action, $dto);

            case HilosSignalConstants::LOGS_FOLLOW_START:
                if (!$dto instanceof LogsFollowStartActionDTO) {
                    throw new InvalidActionPayloadException($action, LogsFollowStartActionDTO::class, $dto);
                }

                return $this->startFollow($acceptKey, $action, $dto);

            case HilosSignalConstants::LOGS_FOLLOW_STOP:
                if (!$dto instanceof LogsFollowStopActionDTO) {
                    throw new InvalidActionPayloadException($action, LogsFollowStopActionDTO::class, $dto);
                }

                $this->stopFollow($acceptKey);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Releases the follow of a connection that left the page or lost its socket.
     *
     * The framework delivers this on an explicit unsubscribe AND on the socket closing
     * ({@see WorkerManager::dispatchPageUnsubscribeIfTrackedOnConnectionClose()}), so a closed tab
     * and a walk to another page release the reader by the same path. A viewer that vanishes
     * without either is caught on the owner's own tick, against the connection roster.
     *
     * @param string $acceptKey WebSocket accept key
     * @throws InvalidArgumentException When the frame to the owner cannot be named
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        $this->stopFollow($acceptKey);
    }

    /**
     * Hands one read to the node that owns the file and lets that node answer it.
     *
     * @param string $acceptKey Accept key of the connection waiting for the page of lines
     * @param string $action Action name the reply acknowledges
     * @param LogsReadLinesActionDTO $dto Validated read request
     * @return ?ActionReplyDTO Always null: the answer is owed by the owner, not by this page
     * @throws TableActionException When the request names a node this cluster does not have, or one
     *     the master last saw offline
     * @throws RtActionsStateCollectionNullException When the cluster roster is unavailable
     * @throws InvalidArgumentException When the frame to the owner cannot be named
     */
    private function forwardRead(string $acceptKey, string $action, LogsReadLinesActionDTO $dto): ?ActionReplyDTO
    {
        $this->requireLiveNode($dto->nodeId);

        // Deferring is the promise that somebody else acks, so it is made only when there is an
        // ack to make: an untracked read has nothing to correlate and is answered by nobody -
        // the same shape the deliveries page hands its writes over in.
        $requestId = $this->currentActionRequestId();
        $this->sendToAgent(
            HilosSignalConstants::LOGS_AGENT_READ_LINES,
            LogsReadLinesSignalData::fromAction($dto, $acceptKey, $action, $requestId),
        );
        if ($requestId !== null) {
            $this->deferActionReply();
        }

        return null;
    }

    /**
     * Starts a follow, replacing whatever this connection was following before.
     *
     * The removal goes out FIRST and only when the node changes: the owner keys its readers by
     * accept key, so a second start on the same node replaces the entry by itself, while a start
     * on a different node would leave the previous owner reading a file for a viewer who has
     * stopped listening to it.
     *
     * @param string $acceptKey Accept key of the connection that will receive the appended lines
     * @param string $action Action name the reply acknowledges
     * @param LogsFollowStartActionDTO $dto Validated follow request
     * @return ?ActionReplyDTO Always null: the first page is owed by the owner, not by this page
     * @throws TableActionException When the request names a node this cluster does not have, or one
     *     the master last saw offline
     * @throws RtActionsStateCollectionNullException When the cluster roster is unavailable
     * @throws InvalidArgumentException When the frame to the owner cannot be named
     */
    private function startFollow(string $acceptKey, string $action, LogsFollowStartActionDTO $dto): ?ActionReplyDTO
    {
        $this->requireLiveNode($dto->nodeId);

        $requestId = $this->currentActionRequestId();
        if ($requestId === null) {
            // The follow id IS the request id: every frame the owner sends is stamped with it, and
            // a viewer that did not track its own start has nothing to match those frames against.
            // There is no follow to begin, so none is begun.
            $this->logAgentInfo('Ignoring an untracked log follow: no request id to stamp its frames with');

            return null;
        }

        if ((self::$followNodeByAcceptKey[$acceptKey] ?? $dto->nodeId) !== $dto->nodeId) {
            $this->stopFollow($acceptKey);
        }

        self::$followNodeByAcceptKey[$acceptKey] = $dto->nodeId;
        $this->sendToAgent(
            HilosSignalConstants::LOGS_AGENT_FOLLOW_START,
            LogsFollowStartSignalData::fromAction($dto, $acceptKey, $action, $requestId),
        );
        $this->deferActionReply();

        return null;
    }

    /**
     * Tells the owner this page recorded to drop the connection's follow, and forgets it.
     *
     * Addressed to the recorded node rather than to any id the browser sent: a switch that has
     * already moved on would send the removal to a node that is not holding the follow, and leave
     * the one that is reading for nobody. A connection that was not following is silently nothing
     * to do - the removal arrives on every unsubscribe of this page, following or not.
     *
     * @param string $acceptKey Accept key of the connection whose follow is being removed
     * @throws InvalidArgumentException When the frame to the owner cannot be named
     */
    private function stopFollow(string $acceptKey): void
    {
        $nodeId = self::$followNodeByAcceptKey[$acceptKey] ?? null;
        if ($nodeId === null) {
            return;
        }

        unset(self::$followNodeByAcceptKey[$acceptKey]);
        $this->sendToAgent(
            HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP,
            new LogsFollowStopSignalData($nodeId, $acceptKey),
        );
    }

    /**
     * Refuses a node the operator can still do something about, before the frame travels.
     *
     * Two refusals and not one, because they mean opposite things to the person reading: an id
     * no node ever answered to is a stale or mistyped choice, while a node the master last saw
     * offline is the machine whose failure is the very reason the logs are being opened.
     *
     * An empty id is this node and is not looked up at all - a single-node install publishes
     * itself under one, so a lookup would be asking whether this machine exists.
     *
     * Checking here is what keeps an undeliverable request from hanging: a frame sent to a node
     * that is not there is answered by nobody, and the browser would sit on the request until
     * its own action timeout expired.
     *
     * @param string $nodeId Node id from the request, empty for this node
     * @throws TableActionException When no such node is known, or the master last saw it offline
     * @throws RtActionsStateCollectionNullException When the cluster roster is unavailable
     */
    private function requireLiveNode(string $nodeId): void
    {
        if ($nodeId === '') {
            return;
        }

        $node = Hilos::$rt->hilosClusterNodes[$nodeId];
        if ($node === null) {
            throw new TableActionException("Unknown cluster node: {$nodeId}");
        }
        if (!$node->online) {
            throw new TableActionException("Cluster node {$nodeId} is offline");
        }
    }
}
