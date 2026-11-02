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

    /**
     * Reads the join of a row joined by a field of its anchor, the one row that declares no key of its own (HIL-1662).
     *
     * The form a list declares to show a row of another table its anchor points at by a foreign key:
     * no ITEM_KEY, not MANY, a database source, and VIA naming exactly one pair - the source's primary
     * key and the anchor field holding it. Such a row is read by the key the anchor fragment holds, and
     * a change of its source is delivered to the anchor items whose field points at the changed row,
     * never to an item whose own key merely equals the changed row's id. No vocabulary constant names
     * the form: the absent key and the single VIA pair do. Only the row's own shape is read here; that
     * the row is not the anchor, and what the mounted tables say of both columns, is the topology
     * validator's to judge.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @return ?array{0: string, 1: string} The source column VIA joins by, and the anchor field it reads
     *     its value from; null when the row is not of this form
     */
    public static function anchorFieldJoin(array $rowConfig): ?array
    {
        if (
            array_key_exists(BrowserListFieldKey::ITEM_KEY, $rowConfig)
            || array_key_exists(BrowserTableFieldKey::ROW_KEY, $rowConfig)
            || ($rowConfig[BrowserFieldKey::MANY] ?? false) === true
        ) {
            return null;
        }

        $source = $rowConfig[BrowserFieldKey::SOURCE] ?? null;
        $via = $rowConfig[BrowserFieldKey::VIA] ?? null;
        if (
            !is_array($source)
            || ($source[BrowserSourceKey::TYPE] ?? null) !== BrowserSourceType::DB
            || !is_array($via)
            || count($via) !== 1
        ) {
            return null;
        }

        $sourceField = array_key_first($via);
        $anchorField = $via[$sourceField];

        return is_string($sourceField) && $sourceField !== '' && is_string($anchorField) && $anchorField !== ''
            ? [$sourceField, $anchorField]
            : null;
    }

    /**
     * Reads the source field a row config projects under one wire name.
     *
     * A FIELDS entry is `source => wire` or a bare name that is both, and a VIA join reads the anchor
     * fragment by the wire name; asking the anchor's table for the rows that hold a value needs the
     * source field behind that name.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param string $wireField Wire name the row's fragment carries
     * @return ?string Source field projected under that name, or null when FIELDS does not project it
     */
    public static function projectedSourceField(array $rowConfig, string $wireField): ?string
    {
        $fields = $rowConfig[BrowserFieldKey::FIELDS] ?? [];
        if (!is_array($fields)) {
            return null;
        }

        foreach ($fields as $sourceField => $field) {
            if ($field === $wireField) {
                return is_string($sourceField) ? $sourceField : $field;
            }
        }

        return null;
    }
}
