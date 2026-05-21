<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Guardian;

use Demo\Chat\Agents\Hilos\DemoHilosGuardianAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\GuardianAgentRunStartActionDTO;
use Demo\Chat\Core\Page\DTO\GuardianAgentRunStopActionDTO;
use Demo\Chat\Hilos;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Pages\AbstractHilosGuardianAgentPage;
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
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
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
    }

    /**
     * Marks guardian run actions as failed for the initiating client.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Action payload
     * @param Throwable $e Action failure
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        if ($dto instanceof GuardianAgentRunStartActionDTO || $dto instanceof GuardianAgentRunStopActionDTO) {
            $status = Hilos::$rt->guardianAgentStatuses[$dto->agentId] ?? null;
            if ($status === null) {
                Hilos::$rt->guardianAgentStatuses->actions->create($dto->agentId, GuardianRunStatus::FAILED);
            } else {
                $status->actions->setStatus(GuardianRunStatus::FAILED);
            }

            return;
        }

        parent::onActionException($acceptKey, $action, $dto, $e);
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

        $this->agent->startGuardianRun($dto->agentId);
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

        $this->agent->stopGuardianRun($dto->agentId);
    }
}
