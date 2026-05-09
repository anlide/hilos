<?php

declare(strict_types=1);

namespace Demo\Chat\Environment;

use Hilos\Environment\EnvAccessor;

/**
 * Chat demo environment accessor with project-specific catalog defaults.
 */
final class ChatEnvAccessor extends EnvAccessor
{
    /**
     * Returns the chat demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    protected function getCatalog(): array
    {
        return ChatEnvCatalog::getCatalog();
    }
}
