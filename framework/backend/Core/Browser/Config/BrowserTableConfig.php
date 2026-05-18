<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Browser row projection config declared by one table.
 */
final class BrowserTableConfig
{
    /**
     * @param list<array<string, mixed>> $rowConfigs Row source configs
     */
    private function __construct(
        private readonly array $rowConfigs,
    ) {
    }

    /**
     * Builds table browser metadata from a table BROWSER constant.
     *
     * @param array<string, mixed> $config Table BROWSER constant
     * @return self Table browser config
     */
    public static function fromArray(array $config): self
    {
        $rows = $config[BrowserConfigKey::ROWS] ?? [];

        return new self(
            rowConfigs: is_array($rows)
                ? array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)))
                : [],
        );
    }

    /**
     * Reports whether this table has browser row configs.
     *
     * @return bool True when no browser rows are declared
     */
    public function isEmpty(): bool
    {
        return $this->rowConfigs === [];
    }

    /**
     * Returns row source configs.
     *
     * @return list<array<string, mixed>> Row source configs
     */
    public function rowConfigs(): array
    {
        return $this->rowConfigs;
    }
}
