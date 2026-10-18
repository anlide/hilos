<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree\Foreign;

/**
 * A constant that lives in another namespace, so naming it takes an import.
 */
final class ScopeNames
{
    /** Option name carried by a class constant rather than a literal. */
    public const string SCOPE = 'scope';
}
