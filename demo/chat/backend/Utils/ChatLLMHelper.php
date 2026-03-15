<?php

declare(strict_types=1);

namespace Demo\Chat\Utils;

/**
 * ChatLLMHelper - Helper methods for chat LLM configuration.
 *
 * Domain logic for LLM prompts (language instructions, etc.).
 */
final class ChatLLMHelper
{
    /**
     * Get language instruction for LLM prompt (English instruction text).
     *
     * @param string $lang ISO 639-1 language code (ru, en, de, fr, es, etc.)
     * @return string Instruction text (e.g. "Respond in Russian.")
     */
    public static function getLanguageInstruction(string $lang): string
    {
        return match (strtolower(trim($lang)) ?: 'ru') {
            'ru' => 'Respond in Russian.',
            'en' => 'Respond in English.',
            'de' => 'Respond in German.',
            'fr' => 'Respond in French.',
            'es' => 'Respond in Spanish.',
            default => 'Match the language of recent messages.',
        };
    }
}
