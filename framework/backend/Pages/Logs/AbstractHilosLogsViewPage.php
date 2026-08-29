<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Log\LogStoreAgent;
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
 */
abstract class AbstractHilosLogsViewPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_VIEW;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW,
    ];

    public const array ACTIONS = [
        HilosSignalConstants::LOGS_READ_LINES => LogsReadLinesActionDTO::class,
    ];

    /**
     * Forwards one read to the node that owns the file and lets that node answer it.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @return ?ActionReplyDTO Always null: the answer is owed by the owner, not by this page
     * @throws AgentUnknownActionException When the page does not support the action
     * @throws InvalidActionPayloadException When the payload is not the read request this action declares
     * @throws TableActionException When the request names a node this cluster does not have, or one
     *     the master last saw offline
     * @throws RtActionsStateCollectionNullException When the cluster roster is unavailable
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        if ($action !== HilosSignalConstants::LOGS_READ_LINES) {
            throw new AgentUnknownActionException("Unknown action: {$action}");
        }
        if (!$dto instanceof LogsReadLinesActionDTO) {
            throw new InvalidActionPayloadException($action, LogsReadLinesActionDTO::class, $dto);
        }

        $this->requireLiveNode($dto->nodeId);

        // Deferring is the promise that somebody else acks, so it is made only when there is an
        // ack to make: an untracked read has nothing to correlate and is answered by nobody -
        // the same shape the deliveries page hands its writes over in.
        $requestId = $this->currentActionRequestId();
        $this->agent->sendToAgent(
            HilosSignalConstants::LOGS_AGENT_READ_LINES,
            LogsReadLinesSignalData::fromAction($dto, $acceptKey, $action, $requestId),
        );
        if ($requestId !== null) {
            $this->deferActionReply();
        }

        return null;
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
     * Checking here is what keeps an undeliverable read from hanging: a frame sent to a node
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
