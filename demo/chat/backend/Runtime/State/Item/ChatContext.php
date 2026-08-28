<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\RtState;

/**
 * ChatContext - Singleton runtime data for chat context analysis.
 *
 * Stores only LLM-produced data: topic, summary, topicConfidence.
 * Id is always "main".
 */
final class ChatContext extends RtState
{
    public const string ID_MAIN = 'main';
    public const string topic = 'topic';
    public const string topicConfidence = 'topicConfidence';
    public const string summary = 'summary';

    /** Current conversation topic (null if none). */
    public ?string $topic = null;

    /** Topic confidence 0..1. */
    public float $topicConfidence = 0.0;

    /** LLM-generated summary of recent messages (null until one is produced). */
    public ?string $summary = null;

    /**
     * Create empty chat context instance.
     *
     * @return static New instance
     */
    public static function create(): static
    {
        $instance = new static();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Create instance from row data (e.g. from persistence).
     *
     * @param array<string, mixed> $row Row data with topic, topicConfidence, summary
     * @return static New instance
     * @throws InvalidFormatException When the row is missing a field the context is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->topic = self::optionalString($row, self::topic);
        $instance->topicConfidence = self::requireFloat($row, self::topicConfidence);
        $instance->summary = self::optionalString($row, self::summary);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime singleton key for the shared chat context row.
     *
     * @return string Runtime singleton item key
     */
    public static function getRtCollectionKey(): string
    {
        return ChatRtContext::chatContext;
    }

    /**
     * Apply diff to state (partial update).
     *
     * @param array<string, mixed> $diff Fields to update (topic, topicConfidence, summary)
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->topic = self::patchOptionalString($diff, self::topic, $this->topic);
        $this->topicConfidence = self::patchFloat($diff, self::topicConfidence, $this->topicConfidence);
        $this->summary = self::patchOptionalString($diff, self::summary, $this->summary);
    }

    /**
     * Get state ID (always "main" for chat context).
     *
     * @return string State ID
     */
    public function getId(): string
    {
        return self::ID_MAIN;
    }

    /**
     * Convert state to array for persistence/serialization.
     *
     * @return array<string, mixed> State as associative array
     */
    public function toArray(): array
    {
        return [
            self::topic => $this->topic,
            self::topicConfidence => $this->topicConfidence,
            self::summary => $this->summary,
        ];
    }
}
