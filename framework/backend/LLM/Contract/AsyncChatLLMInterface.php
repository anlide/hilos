<?php

declare(strict_types=1);

namespace Hilos\LLM\Contract;

use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;

/**
 * AsyncChatLLMInterface - Contract for non-blocking chat LLM providers
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
     * @return bool True if request started, false if client is busy
     */
    public function startGenerate(array $messages, ChatGenerateOptions $options): bool;

    /**
     * Advance the async state machine. Call in event loop each tick.
     *
     * @param float $currentTimeMs Current time in milliseconds
     */
    public function tick(float $currentTimeMs): void;

    /**
     * Check if generation result is available.
     *
     * @return bool True if result is ready for retrieval
     */
    public function hasResult(): bool;

    /**
     * Get generated text. Clears result after retrieval.
     *
     * @return ?string Generated text, or null on error/timeout
     */
    public function getResult(): ?string;

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
