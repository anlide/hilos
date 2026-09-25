<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/**
 * The one reading of a MIME type the upload checks share (HIL-135).
 *
 * A browser declares whatever it has - with parameters, in any case, sometimes nothing - and
 * the checks compare it against a target's list. Both sides go through here, so a list entry
 * and a declared type can never disagree on spelling alone.
 */
final class UploadMime
{
    /** Type a file gets when the browser declared none. */
    public const string FALLBACK = 'application/octet-stream';

    /** Mask suffix that accepts every subtype of one type, as in 'image/*'. */
    public const string ANY_SUBTYPE = '/*';

    /** Separator between the type and its parameters. */
    private const string PARAMETERS_SEPARATOR = ';';

    /** Shape of a type: `type/subtype`, both parts of the token characters in lowercase. */
    private const string TYPE_PATTERN = '/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/';

    /**
     * Brings a declared type to the form the checks compare.
     *
     * @param string $declared Type as the browser declared it
     * @return string Type without parameters, trimmed and lowercase; {@see self::FALLBACK} when nothing is left
     */
    public static function normalize(string $declared): string
    {
        $type = strtolower(trim(explode(self::PARAMETERS_SEPARATOR, $declared, 2)[0]));

        return $type === '' ? self::FALLBACK : $type;
    }

    /**
     * @param string $type Normalized type
     * @return bool Whether the type has the `type/subtype` shape
     */
    public static function isWellFormed(string $type): bool
    {
        return preg_match(self::TYPE_PATTERN, $type) === 1;
    }

    /**
     * Whether a type is named by a list of exact types and masks.
     *
     * @param string $type Normalized type
     * @param list<string> $masks Exact types, or masks like 'image/*'
     * @return bool Whether any entry names the type
     */
    public static function matches(string $type, array $masks): bool
    {
        foreach ($masks as $mask) {
            if ($mask === $type) {
                return true;
            }
            if (str_ends_with($mask, self::ANY_SUBTYPE)
                && str_starts_with($type, substr($mask, 0, -strlen(self::ANY_SUBTYPE)) . '/')) {
                return true;
            }
        }

        return false;
    }
}
