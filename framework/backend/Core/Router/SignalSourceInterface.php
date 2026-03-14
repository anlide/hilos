<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * SignalSourceInterface - Interface for signal source identifiers.
 *
 * Represents a signal source with three parts: source, type, and index.
 */
interface SignalSourceInterface
{
    /**
     * Get signal source (e.g., 'websocket', 'daemon', 'worker', 'http', 'agent')
     *
     * @return string Signal source
     */
    public function getSource(): string;

    /**
     * Get signal source type (optional, can be null)
     *
     * @return ?string Signal source type or null
     */
    public function getType(): ?string;

    /**
     * Get signal source index (optional, can be null)
     *
     * @return ?string Signal source index or null
     */
    public function getIndex(): ?string;
}
