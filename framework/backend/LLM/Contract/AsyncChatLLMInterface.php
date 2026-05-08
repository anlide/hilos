<?php

declare(strict_types=1);

namespace Hilos\LLM\Contract;

use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMException;

/**
 * AsyncChatLLMInterface - Contract for non-blocking chat LLM providers.
 *
 * Unified interface for asynchronous text generation from messages.
 * Implemented by AsyncOllamaChatProvider (local) and AsyncOpenAIChatProvider (external).
 */
interface AsyncChatLLMInterface
{
    /**
     * Start non-blocking text generation. Call tick() until hasResult() is true.
     *
     * @param list<Message|array{role: string, content: string}> $messages Chat messages
     * @param ChatGenerateOptions $options Generation options
     * @throws LLMException When generation cannot be started
     */
    public function startGenerate(array $messages, ChatGenerateOptions $options): void;

    /**
     * Advance the async state machine. Call in event loop each tick.
     *
     * @param float $currentTimeMs Current time in milliseconds
     * @throws LLMException When the active generation request fails
     */
    public function tick(float $currentTimeMs): void;

    /**
     * Check if generation result is available.
     *
     * @return bool True if result is ready for retrieval
     */
    public function hasResult(): bool;

    /**
     * Consume generated text and clear the result buffer.
     *
     * @return string Generated text
     * @throws LLMException When no result is available or provider response is invalid
     */
    public function consumeResult(): string;

    /**
     * Check if generation is in progress.
     *
     * @return bool True if generation is running
     */
    public function isBusy(): bool;

    /**
     * Reset client to idle state (cancel pending request).
     */
    public function reset(): void;
}
