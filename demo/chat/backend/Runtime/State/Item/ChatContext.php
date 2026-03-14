<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Hilos\Runtime\State\Item\RtState;

/**
 * ChatContext state - singleton runtime data for chat context analysis.
 *
 * Stores only LLM-produced data: topic, summary, topicConfidence.
 * Id is always "main".
 *
 * @property ?string $topic Current conversation topic (null if none)
 * @property float $topicConfidence Topic confidence 0..1
 * @property string $summary LLM-generated summary of recent messages
 */
class ChatContext extends RtState
{
    public const string ID_MAIN = 'main';
    public const string topic = 'topic';
    public const string topicConfidence = 'topicConfidence';
    public const string summary = 'summary';

    private ?string $topic = null;
    private float $topicConfidence = 0.0;
    private string $summary = '';

    /**
     * Create empty chat context instance.
     *
     * @return static New instance
     */
    public static function create(): static
    {
        return new static();
    }

    /**
     * Create instance from row data (e.g. from persistence).
     *
     * @param array<string, mixed> $row Row data with topic, topicConfidence, summary
     * @return static New instance
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $topicVal = $row[self::topic] ?? null;
        $instance->topic = $topicVal !== null && $topicVal !== '' ? (string)$topicVal : null;
        $instance->topicConfidence = (float)($row[self::topicConfidence] ?? 0.0);
        $instance->summary = (string)($row[self::summary] ?? '');
        return $instance;
    }

    /**
     * Apply diff to state (partial update).
     *
     * @param array<string, mixed> $diff Fields to update (topic, topicConfidence, summary)
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::topic, $diff)) {
            $v = $diff[self::topic];
            $this->topic = $v !== null && $v !== '' ? (string)$v : null;
        }
        if (array_key_exists(self::topicConfidence, $diff)) {
            $this->topicConfidence = (float)$diff[self::topicConfidence];
        }
        if (array_key_exists(self::summary, $diff)) {
            $this->summary = (string)$diff[self::summary];
        }
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
     * Returns property value by name.
     *
     * @param string $name Property name (topic, topicConfidence, summary)
     * @return mixed Property value
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::topic => $this->topic,
            self::topicConfidence => $this->topicConfidence,
            self::summary => $this->summary,
            default => parent::__get($name),
        };
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
