<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Guardian;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\GuardianAgentRunStartActionDTO;
use Demo\Chat\Core\Page\DTO\GuardianAgentRunStopActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Pages\AbstractHilosGuardianAgentPage;
use Hilos\Utils\Logger;
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
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        try {
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
                    return;
            }
        } catch (Throwable $e) {
            Logger::logAgentError('GuardianAgentPage', "Action {$action} failed: {$e->getMessage()}");

            if ($dto instanceof GuardianAgentRunStartActionDTO || $dto instanceof GuardianAgentRunStopActionDTO) {
                $this->sendToUser(
                    HilosSignalConstants::GUARDIAN_AGENT_STATUS_UPDATE,
                    $acceptKey,
                    new SignalData([
                        'agentId' => $dto->agentId,
                        'status' => 'failed',
                    ]),
                );
            }
        }
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
