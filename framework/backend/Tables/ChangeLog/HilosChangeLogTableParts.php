<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableSearchTerm;
use Hilos\Database\ChangeLog\Section\ChangeLogPerson;
use Hilos\Utils\Helpers\UtcMoment;

/** Shared wire values and window addresses of the two journal tables. */
final class HilosChangeLogTableParts
{
    public const string ANCHOR_CREATED_AT = 'createdAt';
    public const string ANCHOR_ID = 'id';
    public const string ANCHOR_KIND = 'kind';

    /**
     * @param int $moment UTC epoch milliseconds
     * @return string UTC ISO timestamp with milliseconds for browser rendering
     */
    public static function displayTime(int $moment): string
    {
        return substr(UtcMoment::fromMs($moment), 0, 23) . 'Z';
    }

    /**
     * @param int $moment UTC epoch milliseconds
     * @return int Exact source moment for a window anchor
     */
    public static function anchorTime(int $moment): int
    {
        return $moment;
    }

    /**
     * @param TableAnchorDTO $anchor Browser-returned boundary
     * @return int UTC source moment in epoch milliseconds
     * @throws InvalidArgumentException When its timestamp is missing or not an integer
     */
    public static function readAnchorTime(TableAnchorDTO $anchor): int
    {
        $raw = $anchor->values[self::ANCHOR_CREATED_AT] ?? null;
        if (!is_int($raw)) {
            throw new InvalidArgumentException('Change log anchor carries an invalid createdAt');
        }
        return $raw;
    }

    /**
     * @param TableAnchorDTO $anchor Browser-returned boundary
     * @return int Journal or receipt row number
     * @throws InvalidArgumentException When its id is missing or invalid
     */
    public static function readAnchorId(TableAnchorDTO $anchor): int
    {
        $id = $anchor->values[self::ANCHOR_ID] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new InvalidArgumentException('Change log anchor carries no id');
        }
        return $id;
    }

    /**
     * @param ?ChangeLogPerson $person Current or deleted person
     * @return array{id: ?int, label: ?string, deleted: bool} Browser attribution
     */
    public static function person(?ChangeLogPerson $person): array
    {
        return ['id' => $person?->userId, 'label' => $person?->label, 'deleted' => $person?->deleted ?? false];
    }

    /**
     * @param mixed $raw Record key from a row payload
     * @return ?list<int|string|null> Valid record key, including null after anonymization
     * @throws InvalidFormatException When a key part is not scalar or null
     */
    public static function readRecordKey(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new InvalidFormatException('Change log row carries an invalid record key');
        }
        foreach ($raw as $part) {
            if (!is_int($part) && !is_string($part) && $part !== null) {
                throw new InvalidFormatException('Change log row carries an invalid record key part');
            }
        }
        return $raw;
    }

    /**
     * @param TableQueryDTO $query Scoped search query
     * @return string|false|null Search term, false when hidden names forbid the term
     */
    public static function who(TableQueryDTO $query): string|false|null
    {
        $term = TableSearchTerm::normalize($query->search);
        if ($term === null) {
            return null;
        }
        if (isset($query->searchableFields[HilosChangeLogFeedTableRow::actorLabel])
            || isset($query->searchableFields[HilosChangeLogFeedTableRow::subjectLabel])) {
            return $term;
        }
        return preg_match('/\A#?[0-9]+\z/', $term) === 1 ? $term : false;
    }

    /**
     * @param mixed $raw Optional period filter value
     * @return ?DateTimeImmutable Inclusive UTC lower bound
     * @throws InvalidArgumentException When the period is unknown
     */
    public static function since(mixed $raw): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || HilosChangeLogPeriod::tryFrom($raw) === null) {
            throw new InvalidArgumentException('Unknown change log period');
        }
        return HilosChangeLogPeriod::from($raw)->since(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** @return ?string Trimmed optional filter value */
    public static function optionalFilter(mixed $raw): ?string
    {
        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }
}
