<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;

/**
 * Deterministic moderation client for the Docker test environment.
 */
final class TestModerationChatClient implements AsyncChatLLMInterface
{
    private ?string $result = null;

    /**
     * Completes moderation immediately with an allow decision.
     *
     * @param array<int, mixed> $messages Moderation prompt messages, accepted for interface compatibility
     * @param ChatGenerateOptions $options Generation options, accepted for interface compatibility
     */
    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        $this->result = '{"allow": true, "reason": "ok"}';
    }

    /**
     * No-op; the test client is complete as soon as generation starts.
     *
     * @param float $currentTimeMs Current loop time
     */
    public function tick(float $currentTimeMs): void
    {
    }

    /**
     * Reports whether the synthetic moderation result is waiting.
     *
     * @return bool True when a result can be consumed
     */
    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    /**
     * Returns and clears the synthetic moderation result.
     *
     * @return string Moderation decision JSON
     */
    public function consumeResult(): string
    {
        $result = $this->result;
        $this->result = null;

        return $result ?? '';
    }

    /**
     * The deterministic client never stays busy across ticks.
     *
     * @return bool Always false
     */
    public function isBusy(): bool
    {
        return false;
    }

    /**
     * Clears any pending synthetic moderation result.
     */
    public function reset(): void
    {
        $this->result = null;
    }
}

