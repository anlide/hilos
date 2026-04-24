<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Guardian;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\GuardianAgentRunStartActionDTO;
use Demo\Chat\Core\Page\DTO\GuardianAgentRunStopActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Pages\AbstractHilosGuardianAgentPage;
use Throwable;

/**
 * GuardianAgentPage - Guardian AI agent page implementation for demo.
 */
final class GuardianAgentPage extends AbstractHilosGuardianAgentPage
{
    /**
     * Handle guardian run start/stop actions.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::GUARDIAN_AGENT_RUN_START:
                if ($dto instanceof GuardianAgentRunStartActionDTO) {
                    $this->handleStart($dto);
                }

                break;

            case ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP:
                if ($dto instanceof GuardianAgentRunStopActionDTO) {
                    $this->handleStop($dto);
                }

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
            $this->sendToUser(
                HilosSignalConstants::GUARDIAN_AGENT_STATUS_UPDATE,
                $acceptKey,
                new SignalData([
                    'agentId' => $dto->agentId,
                    'status' => 'failed',
                ]),
            );

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
        if (!$this->getGuardianAgent()->hasGuardianAgent($dto->agentId)) {
            return;
        }

        $this->getGuardianAgent()->startGuardianRun($dto->agentId);
    }

    /**
     * Handle one guardian run stop action.
     *
     * @param GuardianAgentRunStopActionDTO $dto Action payload
     */
    private function handleStop(GuardianAgentRunStopActionDTO $dto): void
    {
        if (!$this->getGuardianAgent()->hasGuardianAgent($dto->agentId)) {
            return;
        }

        $this->getGuardianAgent()->stopGuardianRun($dto->agentId);
    }
}
