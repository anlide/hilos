<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Guardian;

use Demo\Chat\Agents\Hilos\DemoHilosGuardianAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\Hilos\Guardian\GuardianAgentRunStartActionDTO;
use Demo\Chat\Pages\DTO\Hilos\Guardian\GuardianAgentRunStopActionDTO;
use Demo\Chat\Hilos;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Pages\AbstractHilosGuardianAgentPage;
use Hilos\Runtime\Exception\RtBaseException;
use Throwable;

/**
 * GuardianAgentPage - Guardian AI agent page implementation for demo.
 *
 * @property DemoHilosGuardianAgent $agent
 */
final class GuardianAgentPage extends AbstractHilosGuardianAgentPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_GUARDIAN;

    public const array ACTIONS = [
        ChatSignalConstants::GUARDIAN_AGENT_RUN_START => GuardianAgentRunStartActionDTO::class,
        ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => GuardianAgentRunStopActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN_AGENT,
        BrowserConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
    ];

    /**
     * Handle guardian run start/stop actions.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws RtBaseException When a run the agent failed cannot be recorded as FAILED in runtime state
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case ChatSignalConstants::GUARDIAN_AGENT_RUN_START:
                if (!$dto instanceof GuardianAgentRunStartActionDTO) {
                    throw new InvalidActionPayloadException($action, GuardianAgentRunStartActionDTO::class, $dto);
                }
                $this->handleStart($dto);

                break;

            case ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP:
                if (!$dto instanceof GuardianAgentRunStopActionDTO) {
                    throw new InvalidActionPayloadException($action, GuardianAgentRunStopActionDTO::class, $dto);
                }
                $this->handleStop($dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Handle one guardian run start action.
     *
     * @param GuardianAgentRunStartActionDTO $dto Action payload
     */
    private function handleStart(GuardianAgentRunStartActionDTO $dto): void
    {
        if (!$this->agent->hasGuardianAgent($dto->agentId)) {
            return;
        }

        try {
            $this->agent->startGuardianRun($dto->agentId);
        } catch (Throwable $e) {
            $this->markRunFailed($dto->agentId);

            throw $e;
        }
    }

    /**
     * Handle one guardian run stop action.
     *
     * @param GuardianAgentRunStopActionDTO $dto Action payload
     */
    private function handleStop(GuardianAgentRunStopActionDTO $dto): void
    {
        if (!$this->agent->hasGuardianAgent($dto->agentId)) {
            return;
        }

        try {
            $this->agent->stopGuardianRun($dto->agentId);
        } catch (Throwable $e) {
            $this->markRunFailed($dto->agentId);

            throw $e;
        }
    }

    /**
     * Records a guardian run as failed in runtime state.
     *
     * FAILED is written only here, about a start or stop the agent was actually
     * asked for and which threw. A guard refusal (401, 403, rate limit, the view
     * mode) never reaches this point: the handler does not run (HIL-1252).
     *
     * @param string $agentId Guardian agent identifier
     */
    private function markRunFailed(string $agentId): void
    {
        $status = Hilos::$rt->guardianAgentStatuses[$agentId] ?? null;
        if ($status === null) {
            Hilos::$rt->guardianAgentStatuses->actions->create($agentId, GuardianRunStatus::FAILED);
        } else {
            $status->actions->setStatus(GuardianRunStatus::FAILED);
        }
    }
}
