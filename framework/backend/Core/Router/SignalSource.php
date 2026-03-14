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
}
