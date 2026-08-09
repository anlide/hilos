<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * Negative sample: nothing here is a field key spelled in the wrong case. An
 * UPPER_SNAKE name belongs to a message name or a catalog key, whose snake value is
 * the name of that entry rather than a payload key; a snake name belongs to an SQL
 * column, where snake is exactly right; a value that is a reference is judged where
 * the key is spelled out; and the last one is the shape the rule asks for.
 */
final class WireKeyCaseLookAlikes
{
    public const string SIGNAL_TYPE_SETTING_CHANGED = 'setting_changed';

    public const string CATALOG_ENTRY_DEFAULT_VALUE = 'default_value';

    public const string last_activity = 'last_activity';

    public const string valueSource = ObjectSetting::valueSource;

    public const string createdAt = 'createdAt';
}
