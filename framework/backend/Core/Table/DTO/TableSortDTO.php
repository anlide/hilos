<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Core\Table\TableConstants;

/**
 * One table window's ordering: which field, which direction.
 *
 * The pair travels together or not at all. "No ordering" is the absence of this object (a null
 * sort), so the state "a direction with no field to apply it to" cannot be written down — which
 * is what the flat `sortField` + `sortDirection` pair used to allow, with the empty string
 * standing in for "unsorted" and every consumer left to recognize it.
 *
 * The wire form is the nested `{field, direction}` object the frontend already sends; a payload
 * that names no field decodes to null rather than to a sort over nothing.
 */
final readonly class TableSortDTO
{
    /** Wire key: the field the window is ordered by. */
    public const string FIELD = 'field';

    /** Wire key: the direction the field is ordered in. */
    public const string DIRECTION = 'direction';

    /**
     * @param string $field Field key the window is ordered by
     * @param string $direction TableConstants::ORDER_ASC or TableConstants::ORDER_DESC
     */
    public function __construct(
        public string $field,
        public string $direction = TableConstants::ORDER_ASC,
    ) {
    }

    /**
     * Reads the nested wire sort, or null when the window asked for no ordering.
     *
     * A field is what makes a sort a sort, so a payload without one is not a malformed sort but
     * simply none. The direction is narrowed to the two the tables understand, because it only
     * ever reaches a query as a whitelisted keyword.
     *
     * @param mixed $raw The `sort` value as it arrived on the wire
     * @return ?self The requested ordering, or null when none was requested
     */
    public static function fromWire(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $field = $raw[self::FIELD] ?? null;
        if (!is_string($field) || $field === '') {
            return null;
        }

        $direction = $raw[self::DIRECTION] ?? null;

        return new self(
            $field,
            $direction === TableConstants::ORDER_DESC ? TableConstants::ORDER_DESC : TableConstants::ORDER_ASC,
        );
    }

    /**
     * @return array{field: string, direction: string} Nested wire form
     */
    public function toArray(): array
    {
        return [
            self::FIELD => $this->field,
            self::DIRECTION => $this->direction,
        ];
    }
}
