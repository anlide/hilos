<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * SignalSource - Standard implementation of signal source identifier
 *
 * Represents a signal source with three parts: source, type, and index.
 * Provides constants for common signal sources.
 */
class SignalSource implements SignalSourceInterface
{
    // Signal source constants
    public const string WEBSOCKET = 'websocket';
    public const string DAEMON = 'daemon';
    public const string WORKER = 'worker';
    public const string HTTP = 'http';
    public const string AGENT = 'agent';

    /**
     * SignalSource constructor
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
     * Get signal source
     *
     * @return string Signal source
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Get signal source type
     *
     * @return ?string Signal source type or null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Get signal source index
     *
     * @return ?string Signal source index or null
     */
    public function getIndex(): ?string
    {
        return $this->index;
    }
}
