<?php

declare(strict_types=1);

namespace Hilos\LLM\Agent;

use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Hilos;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Exception\LLMException;
use Hilos\LLM\Routing\LlmProfile;

/**
 * Reusable agent base that drives a non-blocking LLM chat client in the tick loop.
 *
 * Hoists the pull-loop shared by runtime bot-agents: hold one async chat client
 * resolved from a named LLM profile, and each tick advance it, drain a ready
 * result, then start the next request when idle and there is pending work — with
 * uniform LLMException recovery. Subclasses supply the profile key, the pending
 * trigger, request building, and result handling.
 */
abstract class AbstractLlmChatAgent extends AbstractAgent
{
    /** Profile resolved once at construction; source of model/timeout/provider. */
    protected LlmProfile $profile;

    /** Async chat client for this agent's profile. */
    protected AsyncChatLLMInterface $chatClient;

    /**
     * Resolves the agent's profile and builds its chat client.
     *
     * @throws LLMConfigurationException When the profile cannot be resolved, or an external one
     *                                   carries no API key
     */
    public function __construct()
    {
        $this->profile = Hilos::$llm->resolve($this->profileKey());
        $this->chatClient = $this->createChatClient();
    }

    /**
     * @return string The named LLM profile key this agent routes through
     */
    abstract protected function profileKey(): string;

    /**
     * @return bool Whether there is work waiting to start a new request
     */
    abstract protected function hasPendingWork(): bool;

    /**
     * Builds the messages/options and calls startGenerate for the pending work.
     *
     * Called only when the client is idle. Implementations own prompt/options
     * assembly (from {@see self::$profile}) and their own pending-flag lifecycle.
     */
    abstract protected function startRequest(): void;

    /**
     * Handles one completed result string (parse and dispatch/apply).
     *
     * @param string $text Model output consumed from the client
     */
    abstract protected function handleResult(string $text): void;

    /**
     * Builds the chat client for the resolved profile.
     *
     * Overridable so an agent can inject a test double (e.g. moderation tests).
     *
     * @return AsyncChatLLMInterface Async chat client for the profile
     * @throws LLMConfigurationException When an external profile carries no API key
     */
    protected function createChatClient(): AsyncChatLLMInterface
    {
        return ClientFactory::createChatClientForProfile($this->profile);
    }

    /**
     * Handles an LLM error surfaced by the drive loop; logs by default.
     *
     * @param LLMException $error The error raised while driving the client
     */
    protected function onLlmError(LLMException $error): void
    {
        $this->logAgentError($error->getMessage());
    }

    /**
     * Advances the client, drains a ready result, and pumps pending work.
     */
    public function onTick(): void
    {
        try {
            $this->chatClient->tick(microtime(true) * TimeConstants::MS_PER_SECOND);
        } catch (LLMException $error) {
            $this->onLlmError($error);
            $this->chatClient->reset();
            $this->pumpPending();

            return;
        }

        if ($this->chatClient->hasResult()) {
            try {
                $text = $this->chatClient->consumeResult();
            } catch (LLMException $error) {
                $this->onLlmError($error);
                $this->pumpPending();

                return;
            }

            $this->handleResult($text);
        }

        $this->pumpPending();
    }

    /**
     * Starts the next request when the client is idle and work is pending.
     */
    protected function pumpPending(): void
    {
        if ($this->hasPendingWork() && !$this->chatClient->isBusy()) {
            $this->startRequest();
        }
    }
}
