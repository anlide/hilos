<?php

declare(strict_types=1);

namespace Hilos\LLM\Contract;

use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;

/**
 * ChatLLMInterface - Contract for chat/completion LLM providers
 *
 * Unified interface for text generation from messages (chat format).
 * Implemented by both local (Ollama) and external (OpenAI, OpenRouter) providers.
 */
interface ChatLLMInterface
{
    /**
     * Generate text completion from chat messages.
     *
     * @param list<Message|array{role: string, content: string}> $messages Chat messages
     * @param ChatGenerateOptions $options Generation options (model, temperature, timeout)
     *
     * @return ?string Generated text, or null on transport/format errors
     */
    public function generate(array $messages, ChatGenerateOptions $options): ?string;
}
