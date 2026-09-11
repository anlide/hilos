<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * SignalSource - Standard implementation of signal source identifier.
 *
 * Represents a signal source with three parts: source, type, and index.
 * Provides constants for common signal sources.
 */
class SignalSource implements SignalSourceInterface
{
    /** @var string WebSocket source */
    public const string WEBSOCKET = 'websocket';

    /** @var string Daemon source */
    public const string DAEMON = 'daemon';

    /** @var string Worker source */
    public const string WORKER = 'worker';

    /** @var string HTTP source */
    public const string HTTP = 'http';

    /** @var string Agent source */
    public const string AGENT = 'agent';

    /** @var string DB sync source */
    public const string DB = 'db';

    /** @var string RT sync source */
    public const string RT = 'rt';

    /**
     * Creates signal source with source, type and optional index.
     *
     * @param string $source Signal source (use constants from this class)
     * @param ?string $type Signal source type (optional)
     * @param ?string $index Signal source index (optional)
     */
    public function __construct(
        private readonly string $source,
        private readonly ?string $type = null,
        private readonly ?string $index = null,
    ) {
    }

    /**
     * Returns signal source identifier.
     *
     * @return string Signal source
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Returns signal source type.
     *
     * @return ?string Signal source type or null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Returns signal source index.
     *
     * @return ?string Signal source index or null
     */
    public function getIndex(): ?string
    {
        return $this->index;
    }

    /**
     * Spells a sender out in full, for a log line or for a key kept per sender.
     *
     * The source alone answers "which kind of process", which is rarely enough to tell two
     * senders apart; the type and index the sender may have set are what name the instance.
     * They are optional, so they are appended only when present - and an empty string is
     * treated as absent, so a sender without a type never grows a trailing separator.
     *
     * @param SignalSourceInterface $source Source the signal was queued from
     * @return string Source, narrowed by its type and index when the sender set them
     */
    public static function describe(SignalSourceInterface $source): string
    {
        $description = $source->getSource();

        $sourceType = $source->getType();
        if ($sourceType !== null && $sourceType !== '') {
            $description .= "/{$sourceType}";
        }

        $sourceIndex = $source->getIndex();
        if ($sourceIndex !== null && $sourceIndex !== '') {
            $description .= "#{$sourceIndex}";
        }

        return $description;
    }
}
