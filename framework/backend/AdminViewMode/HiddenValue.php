<?php

declare(strict_types=1);

namespace Hilos\AdminViewMode;

/**
 * HiddenValue - the hidden mark a viewer of the admin view mode receives in place of a value (HIL-1250).
 *
 * The server writes it in place of every field that is personal, or about which nothing says whether it
 * is ({@see ViewerFields}); an admin never receives it. It is an object on the wire, `{"_hidden": true}`,
 * and it lives in the value itself rather than in a side list of hidden names: the frontend normalizes a
 * fragment that carries an id into its entity store by (type, id), and a side list would not survive
 * that. The leading underscore is a reserved name - the precedent is the entity's `_standalone`, and no
 * field of the project is named with one - so the mark cannot be mistaken for a real value.
 */
final class HiddenValue
{
    /** The one key of the mark. */
    public const string KEY = '_hidden';

    /**
     * Returns the mark written in place of a hidden value.
     *
     * @return array{_hidden: true} The hidden mark
     */
    public static function mark(): array
    {
        return [self::KEY => true];
    }

    /**
     * Tells whether a value is the hidden mark.
     *
     * @param mixed $value Value read off a frame
     * @return bool Whether the value is the mark and nothing else
     */
    public static function isMark(mixed $value): bool
    {
        return $value === self::mark();
    }
}
