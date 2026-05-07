<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\DTO;

/**
 * Typed runtime update payload for the shared chat context.
 */
final readonly class ChatContextUpdateData
{
    public const string topic = 'topic';
    public const string topicConfidence = 'topicConfidence';
    public const string summary = 'summary';

    /**
     * Creates a complete update payload for the main chat context.
     *
     * @param ?string $topic Current topic, or null when unknown
     * @param float $topicConfidence Topic confidence in the 0..1 range
     * @param string $summary Current chat summary
     */
    public function __construct(
        public ?string $topic,
        public float $topicConfidence,
        public string $summary,
    ) {
    }
}
