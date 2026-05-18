<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * One page-to-table browser binding from project topology.
 */
final class BrowserPageTableBinding
{
    /**
     * @param string $tableKey Bound browser or registered table key
     * @param array<string, mixed> $paramRefs Table param reference declarations
     */
    private function __construct(
        public readonly string $tableKey,
        private readonly array $paramRefs,
    ) {
    }

    /**
     * Builds a table binding from one PAGE_TABLES entry.
     *
     * @param string $tableKey Bound table key
     * @param array<string, mixed> $config PAGE_TABLES binding config
     * @return self Table binding
     */
    public static function fromArray(string $tableKey, array $config): self
    {
        $paramRefs = $config[BrowserParamKey::PARAMS] ?? [];

        return new self(
            tableKey: $tableKey,
            paramRefs: is_array($paramRefs) ? $paramRefs : [],
        );
    }

    /**
     * Returns table param reference declarations.
     *
     * @return array<string, mixed> Table param reference declarations
     */
    public function paramRefs(): array
    {
        return $this->paramRefs;
    }
}
