<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserAddressVerifySignalData;
use Hilos\Users\DTO\UserAdminCommandSignalData;
use Hilos\Users\DTO\UserAdminWriteSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserEmailChangeSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Users\DTO\UserPasswordRehashSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use PHPUnit\Framework\Assert;

/**
 * The person's agent beside a library under test: raised on the first frame to a person, claimed
 * the way its worker claims it, and run in its own frame (HIL-1404, HIL-1405).
 *
 * A case that drives a coordinator hands each frame the coordinator queued for a person to
 * {@see deliverToPerson()}, then hands the agent's answer back to the coordinator. A case that
 * needs the agent's write to fail declares its own {@see newPersonAgent()}.
 */
trait PersonAgentFrames
{
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
            $ask instanceof UserPasswordRehashSignalData,
            $ask instanceof UserAddressVerifySignalData,
            $ask instanceof UserPasskeyUseSignalData,
            $ask instanceof UserPasswordResetSignalData,
            $ask instanceof UserPasswordChangeSignalData,
            $ask instanceof UserEmailChangeSignalData,
            $ask instanceof UserIdentityUnlinkSignalData => $ask->userId,
            default => Assert::fail('Not a frame to a person\'s agent: ' . get_debug_type($ask)),
        };

        $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            $data,
            '',
            $signal->signalName->getName(),
        ));
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
