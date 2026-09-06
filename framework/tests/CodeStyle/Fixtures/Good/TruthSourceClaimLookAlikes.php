<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Look-alikes TRUTH-SOURCE-CLAIM has to stay silent on: the neighbouring rights, which are
 * not a declaration of ownership; giving a claim back, which is the opposite of making one;
 * the registry named without a call behind it; the same call quoted in a string or written
 * in a comment; and `register()` reached on a class that is no registry at all.
 */
final class TruthSourceClaimLookAlikes
{
    /**
     * @param string $agentId Agent the neighbouring rights are asked for
     */
    public function neighbouringRights(string $agentId): void
    {
        TruthSourceRegistry::registerCreate('look_alike_minted', $agentId);
        RtTruthSourceRegistry::registerDaemon('look_alike_daemon');
        RtTruthSourceRegistry::unregisterDaemon('look_alike_daemon');
    }

    /**
     * @param string $agentId Agent whose claims are given back
     */
    public function givingClaimsBack(string $agentId): void
    {
        TruthSourceRegistry::unregister('look_alike_db', $agentId);
        TruthSourceRegistry::unregisterAgent($agentId);
        RtTruthSourceRegistry::unregisterAgent($agentId);
    }

    /**
     * @return array<int, mixed> Spellings that only look like a claim
     */
    public function mentions(): array
    {
        // TruthSourceRegistry::register('mentioned', $keys, $id) in a comment is text, not a call
        return [
            TruthSourceRegistry::class,
            'RtTruthSourceRegistry::register()',
            TruthSourceClaimLookAlikeRegistrar::register('look_alike_elsewhere'),
        ];
    }
}

/**
 * A class of another trade that happens to own a method of the same name. The rule reads the
 * name on the left of the `::` and not the name of the method alone, so this one is silent.
 */
final class TruthSourceClaimLookAlikeRegistrar
{
    /**
     * @param string $name Name being written down
     * @return string The name, unchanged
     */
    public static function register(string $name): string
    {
        return $name;
    }
}
