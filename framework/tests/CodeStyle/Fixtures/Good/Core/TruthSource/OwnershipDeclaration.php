<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Core\TruthSource;

use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;

/**
 * The one file allowed to lay a claim down in a call, standing here under the path the rule
 * names. It proves the tail match: a real resolver reaches the rule as
 * `Core/TruthSource/OwnershipDeclaration.php`, this copy as `Good/` and the same tail, and
 * both have to stay silent.
 */
final class OwnershipDeclaration
{
    /**
     * @param string $collection Collection the declaration named
     * @param string $ownerId Owner the claim is registered under
     */
    public static function claimDb(string $collection, string $ownerId): void
    {
        TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $ownerId);
    }
}
