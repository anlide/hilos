<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DTO\DaemonAgentRestartRequestSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonAgentRestartActionDTO;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeSubscribeParams;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;

/**
 * AbstractHilosDaemonAgentsPage - Abstract base for Hilos daemon agents list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Daemon\DaemonAgentsPage).
 */
abstract class AbstractHilosDaemonAgentsPage extends AbstractHilosDaemonNodePage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_DAEMON_AGENTS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART => HilosDaemonAgentRestartActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::DAEMON_AGENT_RESTART_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_AGENTS,
    ];

    /**
     * @param ?ClusterDaemonPicture $before Immutable picture before this portion
     * @throws HilosException When node page data cannot be projected
     * @throws EnvException When cluster mode cannot be read
     */
    public static function onPictureChanged(?ClusterDaemonPicture $before): void
    {
        static::publishNodePicture($before, HilosDaemonAgentsTable::TABLE);
    }

    /** Forgets the comparison state when the page agent stops. */
    public static function onPictureForgotten(): void
    {
        self::forgetNodePages();
    }

    /**
     * Routes an admitted admin action; restart eligibility stays at the master.
     *
     * @param string $acceptKey Initiating connection
     * @param string $action Browser action name
     * @param ActionPayloadDTO $dto Parsed browser payload
     * @return ?ActionReplyDTO No immediate reply; the master's outcome answers the tracked action
     * @throws AgentUnknownActionException When this page does not own the action
     * @throws InvalidActionPayloadException When the action has the wrong DTO
     * @throws PageForbiddenException When the request does not name this connection's subscribed node
     * @throws LogicException When the page has no server agent identity
     * @throws InvalidArgumentException When the worker control signal cannot be queued
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART:
                if (!$dto instanceof HilosDaemonAgentRestartActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosDaemonAgentRestartActionDTO::class, $dto);
                }
                $this->handleRestart($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * @param AgentSignalData $data Master's wrapped restart outcome
     * @param string $sender Full server signal source (unused)
     * @param string $name Page-routed signal name
     * @throws AgentUnknownSignalException When the name is not declared here
     * @throws InvalidAgentSignalPayloadException When the outcome has the wrong DTO
     * @throws InvalidArgumentException When the action reply cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::DAEMON_AGENT_RESTART_DONE:
                if (!$data->data instanceof HandoverAnswerSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, HandoverAnswerSignalData::class, $data->data);
                }
                $this->answerHandover($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * @param string $nodeId Node to describe
     * @return array<string, mixed> Node line and first agent-report verdict
     * @throws HilosException When the shared node projection rejects its source picture
     * @throws EnvException When cluster mode cannot be read
     */
    protected static function nodeData(string $nodeId): array
    {
        return parent::nodeData($nodeId) + [self::PROCESSES_REPORTED => self::processesReportedOf($nodeId)];
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params naming the node
     * @throws HilosException When node data cannot be encoded
     * @return PagePayload Node line and first agent-report verdict
     * @throws MissingPageRouteParamException When the node id is absent
     * @throws EnvException When cluster mode cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $nodeId = HilosDaemonNodeSubscribeParams::fromPageRouteParams($params)->nodeId;

        return static::nodePagePayload($nodeId);
    }

    /** @return array<string, WireField> Node and report status carry no personal data */
    protected static function nodeDataFields(): array
    {
        return [
            self::NODE => WireField::notPersonal(),
            self::PROCESSES_REPORTED => WireField::notPersonal(),
        ];
    }

    /**
     * @param string $acceptKey Initiating connection
     * @param HilosDaemonAgentRestartActionDTO $dto Agent and node requested by the browser
     * @throws PageForbiddenException When this connection is not subscribed to the requested node
     * @throws LogicException When this page has no server agent identity
     * @throws InvalidArgumentException When the worker control signal cannot be queued
     */
    private function handleRestart(string $acceptKey, HilosDaemonAgentRestartActionDTO $dto): void
    {
        $subscription = Hilos::$sr->pageSubscription($acceptKey);
        if ($subscription?->page !== static::PAGE
            || !$subscription->paramEquals(HilosPageRouteParams::HILOS_DAEMON_NODE_ID, $dto->nodeId)
        ) {
            throw new PageForbiddenException('Agent restart must name the currently subscribed daemon node');
        }

        $source = $this->getAgentSignalSource();
        $replyAgentType = $source->getType();
        if ($source->getSource() !== SignalSource::AGENT || $replyAgentType === null || $replyAgentType === '') {
            throw new LogicException('Agent restart page has no server agent return address');
        }
        Hilos::$sr->queueSignal(
            $source,
            new SignalType(SignalTypeConstants::DAEMON_AGENT_RESTART_REQUEST),
            new SignalName(SignalTypeConstants::DAEMON_AGENT_RESTART_REQUEST),
            new DaemonAgentRestartRequestSignalData(
                agentId: $dto->agentId,
                nodeId: $dto->nodeId,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                replyAgentType: $replyAgentType,
                replyAgentIndex: $source->getIndex(),
            ),
        );
        if ($this->currentActionRequestId() !== null) {
            $this->deferActionReply();
        }
    }
}
