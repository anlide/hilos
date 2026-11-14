<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Superfan;

use Demo\Chat\Agents\Hilos\DemoHilosSuperfanAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\Hilos\Superfan\SuperfanAgentRunStartActionDTO;
use Demo\Chat\Pages\DTO\Hilos\Superfan\SuperfanAgentRunStopActionDTO;
use Demo\Chat\Hilos;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\SuperfanRunStatus;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Pages\AbstractHilosSuperfanPage;
use Hilos\Runtime\Exception\RtBaseException;
use Throwable;
use LogicException;
use Hilos\HilosException;

/**
 * SuperfanPage - Superfan AI agent page implementation for demo.
 *
 * @property DemoHilosSuperfanAgent $agent
 */
final class SuperfanPage extends AbstractHilosSuperfanPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_SUPERFANS;

    public const array ACTIONS = [
        ChatSignalConstants::SUPERFAN_AGENT_RUN_START => SuperfanAgentRunStartActionDTO::class,
        ChatSignalConstants::SUPERFAN_AGENT_RUN_STOP => SuperfanAgentRunStopActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SUPERFAN,
        BrowserConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_SUPERFAN_AGENT_ID => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
    ];

    /**
     * Handle superfan run start/stop actions.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws RtBaseException When a run the agent failed cannot be recorded as FAILED in runtime state
     * @throws SourceChangeSubscriberException When a status subscriber fails
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     * @throws HilosException Whatever preparing the RT write's immutable operation raises
     * @throws LogicException When an internal invariant is violated
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case ChatSignalConstants::SUPERFAN_AGENT_RUN_START:
                if (!$dto instanceof SuperfanAgentRunStartActionDTO) {
                    throw new InvalidActionPayloadException($action, SuperfanAgentRunStartActionDTO::class, $dto);
                }
                $this->handleStart($dto);

                break;

            case ChatSignalConstants::SUPERFAN_AGENT_RUN_STOP:
                if (!$dto instanceof SuperfanAgentRunStopActionDTO) {
                    throw new InvalidActionPayloadException($action, SuperfanAgentRunStopActionDTO::class, $dto);
                }
                $this->handleStop($dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Handle one superfan run start action.
     *
     * @param SuperfanAgentRunStartActionDTO $dto Action payload
     */
    private function handleStart(SuperfanAgentRunStartActionDTO $dto): void
    {
        if (!$this->agent->hasSuperfanAgent($dto->agentId)) {
            return;
        }

        try {
            $this->agent->startSuperfanRun($dto->agentId);
        } catch (Throwable $e) {
            $this->markRunFailed($dto->agentId);

            throw $e;
        }
    }

    /**
     * Handle one superfan run stop action.
     *
     * @param SuperfanAgentRunStopActionDTO $dto Action payload
     */
    private function handleStop(SuperfanAgentRunStopActionDTO $dto): void
    {
        if (!$this->agent->hasSuperfanAgent($dto->agentId)) {
            return;
        }

        try {
            $this->agent->stopSuperfanRun($dto->agentId);
        } catch (Throwable $e) {
            $this->markRunFailed($dto->agentId);

            throw $e;
        }
    }

    /**
     * Records a superfan run as failed in runtime state.
     *
     * FAILED is written only here, about a start or stop the agent was actually
     * asked for and which threw. A guard refusal (401, 403, rate limit, the view
     * mode) never reaches this point: the handler does not run (HIL-1252).
     *
     * @param string $agentId Superfan agent identifier
     */
    private function markRunFailed(string $agentId): void
    {
        $status = Hilos::$rt->superfanAgentStatuses[$agentId] ?? null;
        if ($status === null) {
            Hilos::$rt->superfanAgentStatuses->actions->create($agentId, SuperfanRunStatus::FAILED);
        } else {
            $status->actions->setStatus(SuperfanRunStatus::FAILED);
        }
    }
}
