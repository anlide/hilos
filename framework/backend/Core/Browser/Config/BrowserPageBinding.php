<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * One page-to-table browser binding from project topology.
 */
final class BrowserPageBinding
{
    /**
     * @param string $browserKey Bound browser or registered table key
     * @param array<string, mixed> $paramRefs Table param reference declarations
     */
    private function __construct(
        public readonly string $browserKey,
        private readonly array $paramRefs,
    ) {
    }

    /**
     * Builds a table binding from one PAGE_TABLES entry.
     *
     * @param string $browserKey Bound table key
     * @param array<string, mixed> $config PAGE_TABLES binding config
     * @return self Table binding
     */
    public static function fromArray(string $browserKey, array $config): self
    {
        $paramRefs = $config[BrowserParamKey::PARAMS] ?? [];

        return new self(
            browserKey: $browserKey,
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
