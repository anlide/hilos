<?php

declare(strict_types=1);

namespace Hilos\AdminViewMode;

/**
 * WireField - where one field of a row or a frame comes from, as the admin view mode needs to know it (HIL-1250).
 *
 * A surface declares a map of these, wire name to origin, and {@see ViewerFields} hides for a viewer every
 * field the map does not open. There are three origins and nothing else:
 *
 * - {@see self::column()} - the value of a column of a mounted DB collection. Whether it is shown is the
 *   column's own verdict (`_piiNotPersonal` of its entity, collected by the personal-data registry), so the
 *   declaration only names the column and never decides for it.
 * - {@see self::notPersonal()} - a computed field, or one that came from RT, which no column verdict covers.
 *   Never for a value copied out of a column: a column holding a person's data is not declared not
 *   personal for the viewer's sake.
 * - {@see self::each()} - an object, or a list of objects, whose fields are declared by a map of their own.
 *
 * A field no map names is hidden, which is what keeps a surface added later safe from birth.
 */
final readonly class WireField
{
    /**
     * @param ?string $collection Collection whose column the value is, or null for another origin
     * @param ?string $field Object field or column name of that collection, or null for another origin
     * @param ?array<string, WireField> $fields Fields of the nested object or of each object of the list,
     *     or null when the value is not nested
     */
    private function __construct(
        public ?string $collection,
        public ?string $field,
        public ?array $fields,
    ) {
    }

    /**
     * Declares the value of an Object field (or a column) of a mounted DB collection.
     *
     * @param string $collection Collection name the value comes from, as mounted on the database context
     * @param string $field Object field or column name the value comes from
     * @return self Origin shown only when the column is in `_piiNotPersonal` of its entity
     */
    public static function column(string $collection, string $field): self
    {
        return new self($collection, $field, null);
    }

    /**
     * Declares a computed field, or a field that came from RT, not personal.
     *
     * @return self Origin shown to a viewer as it is
     */
    public static function notPersonal(): self
    {
        return new self(null, null, null);
    }

    /**
     * Declares an object, or a list of objects, whose fields are declared by a map of their own.
     *
     * @param array<string, WireField> $fields Fields of the object, or of each object of the list
     * @return self Origin whose value is walked field by field; a scalar in its place is hidden
     */
    public static function each(array $fields): self
    {
        return new self(null, null, $fields);
    }

    /**
     * Tells whether the value is the column of a collection.
     *
     * @return bool Whether this origin was declared by {@see self::column()}
     */
    public function isColumn(): bool
    {
        return $this->collection !== null && $this->field !== null;
    }
}
