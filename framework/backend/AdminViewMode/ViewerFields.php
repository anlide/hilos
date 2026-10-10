<?php

declare(strict_types=1);

namespace Hilos\AdminViewMode;

use Closure;
use Throwable;

/**
 * ViewerFields - hides the fields of a fragment a viewer of the admin view mode may not see (HIL-1250).
 *
 * One walk for every point of the wire: the row of a typed table, the declarative fields of a source, the
 * page's own data and the frames of pages that keep a subscriber set of their own. The walk reads the
 * declaration of the fragment ({@see WireField}) and writes {@see HiddenValue::mark()} in place of every
 * value nothing opened. Column and settings visibility are asked of the caller, which knows their verdicts.
 *
 * Pure function of its inputs: no facade reads, no database, no registry.
 */
final class ViewerFields
{
    /**
     * Hides the values of a fragment its declaration does not open.
     *
     * A key the map declares as a column stays when the column is shown and is marked otherwise; a key
     * declared not personal stays; a key declared nested is walked by its own map - a list element by
     * element; null stays absent, and any other scalar is marked. A key the map does not name is marked. A key the
     * map names and the fragment does not carry is not added.
     *
     * @param array<array-key, mixed> $payload Fragment as it would travel to an admin
     * @param array<string, WireField> $fields Declaration of the fragment's fields
     * @param Closure(string, string): bool $columnShown Whether a column of a collection is shown to a viewer
     * @param ?Closure(string): bool $settingShown Whether a setting value is shown to a viewer
     * @return array<array-key, mixed> Fragment with the same keys, every value nothing opened replaced by the mark
     */
    public static function hide(array $payload, array $fields, Closure $columnShown, ?Closure $settingShown = null): array
    {
        $hidden = [];
        foreach ($payload as $key => $value) {
            $declared = $fields[$key] ?? null;
            $hidden[$key] = $declared instanceof WireField
                ? self::shownValue($value, $payload, $declared, $columnShown, $settingShown)
                : HiddenValue::mark();
        }

        return $hidden;
    }

    /**
     * Names the top-level fields {@see self::hide()} would leave as they are.
     *
     * A nested field is not counted: its own fields decide, not the field itself, and a caller asking
     * for names (the fields a viewer's window may sort or search by) needs a value it can compare.
     *
     * @param array<string, WireField> $fields Declaration of the fragment's fields
     * @param Closure(string, string): bool $columnShown Whether a column of a collection is shown to a viewer
     * @return list<string> Names of the fields shown to a viewer, in declaration order
     */
    public static function shownNames(array $fields, Closure $columnShown): array
    {
        $names = [];
        foreach ($fields as $name => $declared) {
            if (!$declared instanceof WireField || $declared->fields !== null || $declared->isSetting()) {
                continue;
            }
            if (!$declared->isColumn() || $columnShown($declared->collection, $declared->field) === true) {
                $names[] = (string)$name;
            }
        }

        return $names;
    }

    /**
     * Returns one declared value as a viewer receives it.
     *
     * @param mixed $value Value as it would travel to an admin
     * @param array<array-key, mixed> $fragment Object carrying the value and any key it depends on
     * @param WireField $declared Where the value comes from
     * @param Closure(string, string): bool $columnShown Whether a column of a collection is shown to a viewer
     * @param ?Closure(string): bool $settingShown Whether a setting value is shown to a viewer
     * @return mixed The value itself, the value walked by its own map, or the mark
     */
    private static function shownValue(
        mixed $value,
        array $fragment,
        WireField $declared,
        Closure $columnShown,
        ?Closure $settingShown,
    ): mixed
    {
        if ($declared->fields !== null) {
            if ($value === null) {
                return null;
            }
            return is_array($value)
                ? self::hideNested($value, $declared->fields, $columnShown, $settingShown)
                : HiddenValue::mark();
        }
        if ($declared->isSetting()) {
            return self::settingValueShown($fragment, $declared, $settingShown) ? $value : HiddenValue::mark();
        }
        if (!$declared->isColumn()) {
            return $value;
        }

        return $columnShown($declared->collection, $declared->field) === true ? $value : HiddenValue::mark();
    }

    /**
     * Checks a setting origin against every source key, failing closed on missing or malformed keys.
     *
     * @param array<array-key, mixed> $fragment Object carrying the field
     * @param WireField $declared Origin of the value
     * @param ?Closure(string): bool $settingShown Settings catalog verdict
     * @return bool Whether the value may be shown
     */
    private static function settingValueShown(array $fragment, WireField $declared, ?Closure $settingShown): bool
    {
        if ($settingShown === null) {
            return false;
        }

        try {
            $keys = $declared->settingKeys;
            if ($keys === null) {
                $source = $declared->settingKeySource;
                $key = $source instanceof Closure ? $source($fragment) : ($fragment[$source] ?? null);
                $keys = [$key];
            }

            if ($keys === []) {
                return false;
            }
            foreach ($keys as $key) {
                if (!is_string($key) || $key === '' || $settingShown($key) !== true) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Walks a nested value: one object by its map, or every object of a list.
     *
     * @param array<array-key, mixed> $value Object, or list of objects
     * @param array<string, WireField> $fields Declaration of the object's fields
     * @param Closure(string, string): bool $columnShown Whether a column of a collection is shown to a viewer
     * @param ?Closure(string): bool $settingShown Whether a setting value is shown to a viewer
     * @return array<array-key, mixed> The object hidden by its map, or the list with every element hidden so
     */
    private static function hideNested(array $value, array $fields, Closure $columnShown, ?Closure $settingShown): array
    {
        if (!array_is_list($value)) {
            return self::hide($value, $fields, $columnShown, $settingShown);
        }

        return array_map(
            static fn(mixed $element): array => is_array($element)
                ? self::hide($element, $fields, $columnShown, $settingShown)
                : HiddenValue::mark(),
            $value,
        );
    }
}
