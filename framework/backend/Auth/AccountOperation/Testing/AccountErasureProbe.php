<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Testing;

use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;

/** One bounded capture private to one worker; process death deliberately loses it. */
final class AccountErasureProbe
{
    private ?AccountErasureProbeRequest $armed = null;
    private ?AgentInterface $armedSource = null;
    private ?WorkerAgentMessageDTO $held = null;
    private ?AgentInterface $heldSource = null;
    private int $matched = 0;

    /**
     * @param AccountErasureProbeRequest $request Arm request with optional selectors
     * @param AgentInterface $source Current local instance from AgentManager
     * @throws InvalidFormatException When the probe is occupied or the request names another source
     */
    public function arm(AccountErasureProbeRequest $request, AgentInterface $source): void
    {
        if ($request->action !== AccountErasureProbeAction::Arm || $source->getId() !== $request->agentId) {
            throw new InvalidFormatException('Erasure probe arm requires its current local source');
        }
        if ($this->armed !== null || $this->held !== null) {
            throw new InvalidFormatException('Erasure probe is already occupied');
        }
        $this->armed = $request;
        $this->armedSource = $source;
    }

    /**
     * Run immediately before the ordinary worker sender, after handler completion.
     *
     * @param WorkerAgentMessageDTO $frame Outgoing envelope
     * @param ?AgentInterface $source Current local source, null if it no longer exists
     * @return ?WorkerAgentMessageDTO Frame to send normally, or null while this frame is held
     * @throws InvalidFormatException When a matching envelope has invalid assignment fields
     */
    public function intercept(WorkerAgentMessageDTO $frame, ?AgentInterface $source): ?WorkerAgentMessageDTO
    {
        if ($this->armed === null || $source === null || $source !== $this->armedSource
            || $source->getId() !== $this->armed->agentId
            || !AccountErasureProbeRequest::isFrom($frame, $this->armed->agentId)
            || AccountErasureProbeRequest::kindOf($frame) !== $this->armed->frameKind
            || !$frame->signal->data instanceof AgentSignalData
            || !($frame->signal->data->data instanceof AccountOperationAskSignalData
                || $frame->signal->data->data instanceof AccountOperationAnswerSignalData)) {
            return $frame;
        }
        $payload = $frame->signal->data->data;
        if (($this->armed->operationId !== null && $payload->operationId !== $this->armed->operationId)
            || ($this->armed->stage !== null && $payload->stage !== $this->armed->stage)
            || ($this->armed->assignmentId !== null && $payload->assignmentId !== $this->armed->assignmentId)) {
            return $frame;
        }
        AccountErasureProbeRequest::validateFrame($frame);
        $this->held = $frame;
        $this->heldSource = $source;
        $this->armed = null;
        $this->armedSource = null;
        ++$this->matched;
        return null;
    }

    /**
     * @param string $agentId Requested local source
     * @param ?AgentInterface $source Current local instance, never started for the probe
     * @return WorkerAgentMessageDTO Consumed envelope for the ordinary sender
     * @throws InvalidFormatException When nothing is held, the address differs or the original instance is gone
     */
    public function release(string $agentId, ?AgentInterface $source): WorkerAgentMessageDTO
    {
        if ($this->held === null || $this->held->agentId !== $agentId) {
            throw new InvalidFormatException('Erasure probe holds no frame for this source');
        }
        if ($source === null || $source !== $this->heldSource || $source->getId() !== $agentId) {
            $this->discard();
            throw new InvalidFormatException('Erasure probe source stopped or was replaced; held frame discarded');
        }
        $frame = $this->held;
        $this->discard();
        return $frame;
    }

    /**
     * @throws InvalidFormatException When no envelope is held
     */
    public function discard(): void
    {
        if ($this->held === null) {
            throw new InvalidFormatException('Erasure probe holds no frame');
        }
        $this->held = null;
        $this->heldSource = null;
    }

    /** Clear the arm, held envelope and capture counter. */
    public function clear(): void
    {
        $this->armed = null;
        $this->armedSource = null;
        $this->held = null;
        $this->heldSource = null;
        $this->matched = 0;
    }

    /**
     * @param AccountErasureProbeRequest $request Validated replay request
     * @param ?AgentInterface $source Current live local instance from AgentManager
     * @return list<WorkerAgentMessageDTO> Complete copies for the ordinary sender, bypassing capture
     * @throws InvalidFormatException When this is not a replay or its live source does not exist
     */
    public function replay(AccountErasureProbeRequest $request, ?AgentInterface $source): array
    {
        if ($request->action !== AccountErasureProbeAction::Replay || $request->frame === null || $request->copies === null
            || $source === null || $source->getId() !== $request->agentId) {
            throw new InvalidFormatException('Erasure probe replay requires its live local source');
        }
        return array_fill(0, $request->copies, $request->frame);
    }

    /**
     * @return AccountErasureProbeState Non-consuming transport snapshot
     * @throws InvalidFormatException When the captured envelope cannot form a valid state
     */
    public function inspect(): AccountErasureProbeState
    {
        return new AccountErasureProbeState($this->armed !== null, $this->matched, $this->held);
    }
}
