<?php

declare(strict_types=1);

namespace Hilos\AdminViewMode;

use Closure;

/**
 * WireField - where one field of a row or a frame comes from, as the admin view mode needs to know it (HIL-1250).
 *
 * A surface declares a map of these, wire name to origin, and {@see ViewerFields} hides for a viewer every
 * field the map does not open. Its origins are:
 *
 * - {@see self::column()} - the value of a column of a mounted DB collection. Whether it is shown is the
 *   column's own verdict (`_piiNotPersonal` of its entity, collected by the personal-data registry), so the
 *   declaration only names the column and never decides for it.
 * - {@see self::notPersonal()} - a computed field, or one that came from RT, which no column verdict covers.
 *   Never for a value copied out of a column: a column holding a person's data is not declared not
 *   personal for the viewer's sake.
 * - {@see self::each()} - an object, or a list of objects, whose fields are declared by a map of their own.
 * - {@see self::setting()} and {@see self::settingFrom()} - a value judged by its settings catalog key.
 * - {@see self::settings()} - a derived value shown only when all of its source settings are open.
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
     * @param ?list<string> $settingKeys Fixed settings keys whose visibility controls this value
     * @param string|Closure(array<array-key, mixed>): ?string|null $settingKeySource Field name or resolver of a fragment's key
     */
    private function __construct(
        public ?string $collection,
        public ?string $field,
        public ?array $fields,
        public ?array $settingKeys = null,
        public string|Closure|null $settingKeySource = null,
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
     * @return self Origin walked field by field; null stays null, another scalar is hidden
     */
    public static function each(array $fields): self
    {
        return new self(null, null, $fields);
    }

    /**
     * Declares a value controlled by one fixed settings catalog key.
     *
     * @param string $key Settings catalog key
     * @return self Origin shown only when the key is explicitly open
     */
    public static function setting(string $key): self
    {
        return new self(null, null, null, [$key]);
    }

    /**
     * Declares a value controlled by a settings key in its own fragment.
     *
     * @param string|Closure(array<array-key, mixed>): ?string $source Fragment field or resolver returning the key
     * @return self Origin shown only when the resolved key is explicitly open
     */
    public static function settingFrom(string|Closure $source): self
    {
        return new self(null, null, null, null, $source);
    }

    /**
     * Declares a derived value controlled by every named settings key.
     *
     * @param list<string> $keys Settings catalog keys
     * @return self Origin shown only when all keys are explicitly open
     */
    public static function settings(array $keys): self
    {
        return new self(null, null, null, $keys);
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

    /**
     * Reports whether a settings catalog verdict controls the value.
     *
     * @return bool Whether this field has a setting origin
     */
    public function isSetting(): bool
    {
        return $this->settingKeys !== null || $this->settingKeySource !== null;
    }
}
