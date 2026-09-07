<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

/**
 * PagePayload - The scope payload delivered to a subscribing client.
 *
 * Carries the page scope split by kind: `entities` (fragments per source key,
 * each with its id), plain `data`, `lists` (ordered item collections),
 * `tables` (row collections), and `windows` (the first window of each viewport
 * table). A page contributes the entity/data sections from
 * AbstractPage::buildPagePayload(); the browser layer contributes lists/tables/
 * data from its kind-classified sources. The framework wraps the payload in a
 * PageResponseSignalData with the page key. Every section is a wire payload and
 * is omitted on serialization when empty, so the frontend never receives a JSON
 * array where it expects a map.
 */
final class PagePayload
{
    public const string entities = 'entities';
    public const string data = 'data';
    public const string lists = 'lists';
    public const string tables = 'tables';

    /**
     * Fifth section: the first window of every viewport table the page declares (HIL-642).
     *
     * A window is not a row set but a slice with coordinates, and it is held by the window
     * controller rather than by the page scope, so it travels in a section of its own: put
     * inside `tables`, it would be stored twice and drift apart on the first delta.
     */
    public const string windows = 'windows';

    /** Table section: the row set. */
    public const string rows = 'rows';

    /** List section: the ordered item set. */
    public const string items = 'items';

    /** A row's identity within a table. */
    public const string rowKey = 'rowKey';

    /** An item's identity within a list. */
    public const string itemKey = 'itemKey';

    /** Per-row/per-item source fragments, keyed by source key. */
    public const string slots = 'slots';

    /**
     * Source keys of a table row whose values stopped being kept up to date (HIL-800).
     *
     * A list of the same keys the row's `slots` are keyed by, and only the ones that froze;
     * a row every source of which is current carries no such key at all. Whoever assembled
     * the fragment names its freshness, because only they know what the fragment was read
     * from — a per-row runtime item for a declarative row, a summary over many for a typed one.
     */
    public const string staleSources = 'staleSources';

    /** Removed row/item keys in an incremental update. */
    public const string deleted = 'deleted';

    /** Truncate marker: when true, the list/table is cleared before items in the same payload apply. */
    public const string cleared = 'cleared';

    /**
     * @param array<string, array<string, mixed>|list<array<string, mixed>>> $entities Entity fragments per source key, each carrying its id
     * @param array<string, mixed> $data Plain page-data values per key
     * @param array<string, mixed> $lists Ordered list collections per list key
     * @param array<string, mixed> $tables Row collections per table key
     * @param array<string, mixed> $windows First window per viewport-table key
     */
    public function __construct(
        public readonly array $entities = [],
        public readonly array $data = [],
        public readonly array $lists = [],
        public readonly array $tables = [],
        public readonly array $windows = [],
    ) {
    }

    /**
     * Reports whether the payload carries no sections at all.
     *
     * @return bool True when every section is empty
     */
    public function isEmpty(): bool
    {
        return $this->entities === []
            && $this->data === []
            && $this->lists === []
            && $this->tables === []
            && $this->windows === [];
    }

    /**
     * Serializes the payload, omitting empty sections.
     *
     * @return array<string, mixed> Scope payload with only the present sections
     */
    public function toArray(): array
    {
        $payload = [];
        if ($this->entities !== []) {
            $payload[self::entities] = $this->entities;
        }
        if ($this->data !== []) {
            $payload[self::data] = $this->data;
        }
        if ($this->lists !== []) {
            $payload[self::lists] = $this->lists;
        }
        if ($this->tables !== []) {
            $payload[self::tables] = $this->tables;
        }
        if ($this->windows !== []) {
            $payload[self::windows] = $this->windows;
        }

        return $payload;
    }
}
