<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserAddressVerifySignalData;
use Hilos\Users\DTO\UserAdminCommandSignalData;
use Hilos\Users\DTO\UserAdminWriteSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserBrowserTrustRevokeSignalData;
use Hilos\Users\DTO\UserEmailChangeSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Users\DTO\UserPasswordRehashSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmSignalData;
use Hilos\Users\DTO\UserSecondFactorProveSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindSignalData;
use Hilos\Users\DTO\UserSecondFactorUnlockSignalData;
use Hilos\Users\DTO\UserSecondFactorWaitWriteSignalData;
use Hilos\Users\DTO\UserStepUpCreditSignalData;
use Hilos\Users\DTO\UserStepUpRecordSignalData;
use PHPUnit\Framework\Assert;

/**
 * The person's agent beside a library under test: raised on the first frame to a person, claimed
 * the way its worker claims it, and run in its own frame (HIL-1404, HIL-1405, HIL-1406, HIL-1407).
 *
 * A case that drives a coordinator hands each frame the coordinator queued for a person to
 * {@see deliverToPerson()}, then hands the agent's answer back to the coordinator. A case that
 * needs the agent's write to fail declares its own {@see newPersonAgent()}. A case driving the users
 * library can leave both hands to {@see carryPersonFrames()}, and run a browser action the way the
 * dispatcher runs a tracked one with {@see runTracked()}.
 */
trait PersonAgentFrames
{
    /** Request id of the action {@see runTracked()} runs, which tells its ack from every other signal. */
    private const string TRACKED_REQUEST_ID = 'request-person-agent';
    /** @var array<int, AbstractUserAgent> Agents raised so far, by person */
    private array $personAgents = [];

    /**
     * Builds the agent of one person; a case overrides this to replace a write of the agent.
     *
     * @param string $userId Person id as the agent address carries it
     * @return AbstractUserAgent The agent, not yet claimed
     */
    private function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new IntegrationUserAgent($userId);
    }

    /**
     * @param int $userId Person whose agent is asked for
     * @return AbstractUserAgent The person's agent, holding the claims its worker would lay
     */
    private function personAgent(int $userId): AbstractUserAgent
    {
        if (!isset($this->personAgents[$userId])) {
            $agent = $this->newPersonAgent((string)$userId);
            OwnershipDeclaration::claimAll($agent);
            $this->personAgents[$userId] = $agent;
        }

        return $this->personAgents[$userId];
    }

    /**
     * Runs one step in the person's agent's own frame, the way its worker would.
     *
     * @template T
     * @param int $userId Person whose agent runs the step
     * @param callable(AbstractUserAgent): T $step Step to run as the agent
     * @return T Whatever the step returns
     */
    private function asPerson(int $userId, callable $step): mixed
    {
        $agent = $this->personAgent($userId);

        return ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static fn (): mixed => $step($agent));
    }

    /**
     * Delivers one frame a coordinator queued for a person, in that person's agent's frame.
     *
     * @param SignalDTO $signal Queued frame addressed to a person's agent
     */
    private function deliverToPerson(SignalDTO $signal): void
    {
        $data = $signal->data;
        Assert::assertInstanceOf(AgentSignalData::class, $data);
        $ask = $data->data;
        $userId = match (true) {
            $ask instanceof UserRenameSignalData,
            $ask instanceof UserAdminWriteSignalData,
            $ask instanceof UserAdminCommandSignalData,
            $ask instanceof UserBlockWriteSignalData,
            $ask instanceof UserThemePickWriteSignalData,
            $ask instanceof UserPasswordRehashSignalData,
            $ask instanceof UserAddressVerifySignalData,
            $ask instanceof UserPasskeyUseSignalData,
            $ask instanceof UserPasswordResetSignalData,
            $ask instanceof UserPasswordChangeSignalData,
            $ask instanceof UserEmailChangeSignalData,
            $ask instanceof UserIdentityUnlinkSignalData,
            $ask instanceof UserSecondFactorProveSignalData,
            $ask instanceof UserSecondFactorEnrollConfirmSignalData,
            $ask instanceof UserSecondFactorRemoveSignalData,
            $ask instanceof UserSecondFactorResetCancelSignalData,
            $ask instanceof UserSecondFactorWaitWriteSignalData,
            $ask instanceof UserSecondFactorResetDueSignalData,
            $ask instanceof UserSecondFactorResetRemindSignalData,
            $ask instanceof UserSecondFactorUnlockSignalData,
            $ask instanceof UserStepUpRecordSignalData,
            $ask instanceof UserStepUpCreditSignalData,
            $ask instanceof UserBrowserTrustRevokeSignalData => $ask->userId,
            default => Assert::fail('Not a frame to a person\'s agent: ' . get_debug_type($ask)),
        };

        $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            $data,
            '',
            $signal->signalName->getName(),
        ));
    }

    /**
     * Carries every frame between the users library and the people's agents until none is left
     * (HIL-1406): an ask to the person's agent, an answer of that agent back to the library.
     *
     * Every other signal taken off the queue is put back on it in the order it came, so a case
     * reads the queue as if the hops had never been there.
     *
     * @param AbstractUsersLibraryAgent $library The library that asks and is answered
     * @throws HilosException When the agent or the library fails on a frame
     */
    private function carryPersonFrames(AbstractUsersLibraryAgent $library): void
    {
        $kept = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if (isset(AbstractUserAgent::AGENT_SIGNALS[$name])) {
                $this->deliverToPerson($signal);
            } elseif ($signal->data instanceof AgentSignalData && self::isPersonAnswer($name)) {
                $library->onSignalAgent($signal->data, 'test', $name);
            } else {
                $kept[] = $signal;
            }
        }
        foreach ($kept as $signal) {
            Hilos::$sr?->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }
    }

    /**
     * Runs one browser action on the users library the way the dispatcher runs a tracked one, carries
     * the hops to the person's agent and back, and answers what the browser was told (HIL-1406).
     *
     * A refusal is raised in the words the browser reads, whether the library refused before the hop
     * or the agent's answer refused after it, so a case reads both alike. The ack is taken off the
     * queue; every other signal stays on it.
     *
     * @param AbstractUsersLibraryAgent $library The library that runs the action
     * @param string $acceptKey Tab that submits
     * @param string $action Action wire name
     * @param ActionPayloadDTO $dto Action payload
     * @return ?array<string, mixed> Reply the browser was answered with, or null when it was answered with nothing or by the holder
     * @throws ValidationException When the action was refused
     * @throws HilosException When the library or an agent fails for another reason
     */
    private function runTracked(AbstractUsersLibraryAgent $library, string $acceptKey, string $action, ActionPayloadDTO $dto): ?array
    {
        $library->beginActionDispatch(self::TRACKED_REQUEST_ID);
        try {
            $reply = $library->onAgentAction($acceptKey, $action, $dto);
            $deferred = $library->actionReplyDeferred();
        } finally {
            $library->endActionDispatch();
        }
        if (!$deferred) {
            return $reply?->toArray();
        }

        $this->carryPersonFrames($library);
        $ack = null;
        $kept = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $signal->data instanceof WebSocketSignalData ? $signal->data->data : null;
            if (
                ($payload instanceof PageActionErrorSignalData || $payload instanceof PageActionSuccessSignalData)
                && $payload->requestId === self::TRACKED_REQUEST_ID
                && $payload->action === $action
            ) {
                $ack = $payload;
            } else {
                $kept[] = $signal;
            }
        }
        foreach ($kept as $signal) {
            Hilos::$sr?->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }
        if ($ack instanceof PageActionErrorSignalData) {
            throw new ValidationException($ack->reason);
        }

        return $ack?->reply;
    }

    /**
     * @param string $name Agent-signal name taken off the queue
     * @return bool Whether it is the answer of a person's agent to the users library
     */
    private static function isPersonAnswer(string $name): bool
    {
        // Every ask names its answer _done, and the library declares each answer it takes.
        return str_starts_with($name, 'hilos_user_')
            && str_ends_with($name, '_done')
            && isset(AbstractUsersLibraryAgent::AGENT_SIGNALS[$name]);
    }

    /** Takes back every claim the agents raised by this case laid. */
    private function releasePersonAgents(): void
    {
        foreach ($this->personAgents as $agent) {
            TruthSourceRegistry::unregisterAgent($agent->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
        }
        $this->personAgents = [];
    }
}
