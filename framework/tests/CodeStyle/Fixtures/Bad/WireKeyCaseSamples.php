<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: every constant below declares a field key by its
 * camelCase name and then spells the key in another case, so WIRE-KEY-CASE must
 * report each one — the snake spelling, the capitalized one, both halves of a
 * declaration naming two keys, and a name the lexer hands over as a keyword.
 */
final class WireKeyCaseSamples
{
    public const string createdAt = 'created_at';

    public const string valueSource = 'ValueSource';

    public const string overrideValue = 'override_value', defaultValue = 'default_value';

    public const string default = 'default_kind';
}
