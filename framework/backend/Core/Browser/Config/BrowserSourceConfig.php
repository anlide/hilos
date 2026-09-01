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
        $rows = $config[BrowserListConfigKey::ITEMS] ?? $config[BrowserTableConfigKey::ROWS] ?? [];

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

    /**
     * Reads the column a row config joins its source by, and where that column's value comes from.
     *
     * One rule with two readers, which is why it lives on the config rather than in either of
     * them: the browser reads a join by this column, and the topology validator holds the same
     * column against the child table's indexes. VIA answers first when it is declared, naming the
     * child's column explicitly and taking its value from a field of the anchor fragment;
     * otherwise the row key declaration is the join, and its value is the row key itself.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @return array{0: ?string, 1: ?string} Join column, and the anchor field it reads its value
     *     from - null there means the row key is the value
     */
    public static function joinBy(array $rowConfig): array
    {
        $via = $rowConfig[BrowserFieldKey::VIA] ?? [];
        if (is_array($via)) {
            foreach ($via as $sourceField => $rowField) {
                if (is_string($sourceField) && $sourceField !== '' && is_string($rowField)) {
                    return [$sourceField, $rowField];
                }
            }
        }

        $rowKey = $rowConfig[BrowserListFieldKey::ITEM_KEY] ?? $rowConfig[BrowserTableFieldKey::ROW_KEY] ?? null;

        return is_string($rowKey) && $rowKey !== '' ? [$rowKey, null] : [null, null];
    }
}
