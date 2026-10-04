<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/** Keeps a node journal's byte ceiling at least as large as one rotated file. */
final class AnalyticsJournalCeilingRule implements SettingValueRuleInterface
{
    /**
     * @param mixed $value Value about to be written
     * @return ?string Refusal text, or null when the ceiling can hold a file
     */
    public static function validate(mixed $value): ?string
    {
        if ((is_int($value) || (is_string($value) && ctype_digit($value)))
            && $value >= AnalyticsJournalDirectory::ROTATE_BYTES) {
            return null;
        }

        return 'The analytics journal ceiling must be at least '
            . AnalyticsJournalDirectory::ROTATE_BYTES . ' bytes, one journal file';
    }
}
