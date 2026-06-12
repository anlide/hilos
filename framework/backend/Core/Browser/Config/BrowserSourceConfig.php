<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Browser row projection config declared by one source.
 *
 * Kind-agnostic: a table, a list, and a data source all project rows from the
 * same row-config shape. The source kind (which page_response section the rows
 * feed) is a property of the source class, not of this projection config.
 */
final class BrowserSourceConfig
{
    /**
     * @param list<array<string, mixed>> $rowConfigs Row source configs
     */
    private function __construct(
        private readonly array $rowConfigs,
    ) {
    }

    /**
     * Builds source browser metadata from a source BROWSER constant.
     *
     * @param array<string, mixed> $config Source BROWSER constant
     * @return self Source browser config
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
     * Reports whether this source has browser row configs.
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
