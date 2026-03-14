<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatTopicConstants - Allowed chat topics (from bot seed 001_truncate_and_seed_bot.sql).
 *
 * Extracted from participant bots' topics. Used by ChatContextAnalyzer (must pick
 * topic from this list) and BotAgent leaders (must propose topic from this list).
 */
final class ChatTopicConstants
{
    /** @var list<string> All allowed topics (from seed: participants' topics, deduplicated) */
    public const array TOPICS = [
        'AI',
        'alternate histories',
        'ancient civilizations',
        'archaeology',
        'athletics',
        'audio',
        'biographies',
        'business strategy',
        'climate',
        'comedy',
        'competition',
        'conservation',
        'cooking',
        'creativity',
        'cultures',
        'cuisines',
        'decision-making',
        'ecology',
        'economics',
        'ethics',
        'exercise',
        'film',
        'finance',
        'futurism',
        'gaming',
        'geography',
        'green tech',
        'hardware',
        'history',
        'innovation',
        'investing',
        'languages',
        'literature',
        'logic',
        'markets',
        'music',
        'nutrition',
        'office life',
        'outdoor activities',
        'personal finance',
        'philosophy',
        'politics',
        'pop culture',
        'science',
        'sci-fi',
        'self-improvement',
        'software',
        'space',
        'sports',
        'startups',
        'success stories',
        'sustainability',
        'team dynamics',
        'teamwork',
        'technology',
        'technology predictions',
        'travel',
        'visual arts',
        'wellness',
    ];

    /**
     * Get topics as comma-separated string for prompts.
     *
     * @return string Comma-separated topic list for LLM prompts
     */
    public static function getTopicsForPrompt(): string
    {
        return implode(', ', self::TOPICS);
    }
}
