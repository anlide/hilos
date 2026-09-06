<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Hilos\Core\TruthSource\AbstractTruthSourceRegistry;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Deliberately broken sample: every line below declares ownership in a call, which is what
 * TRUTH-SOURCE-CLAIM refuses - whichever of the three registry names is written, and whether
 * the file is allowed to name them or not. This one is not.
 */
final class TruthSourceClaimSamples
{
    /**
     * @param string $agentId Owner the claim would be laid under
     */
    public function claim(string $agentId): void
    {
        TruthSourceRegistry::register('claim_samples_db', TruthSourceKeys::all(), $agentId);
        RtTruthSourceRegistry::register('claim_samples_rt', TruthSourceKeys::all(), $agentId);
        AbstractTruthSourceRegistry::register('claim_samples_base', TruthSourceKeys::all(), $agentId);
    }
}

/**
 * The road round the three names above, and the reason `self` is one of them: a class
 * extending a registry reaches the inherited method under its own name, and the claim is
 * made exactly the same.
 */
final class TruthSourceClaimSamplesRegistry extends TruthSourceRegistry
{
    /**
     * @param string $agentId Owner the claim would be laid under
     */
    public static function claimThroughSelf(string $agentId): void
    {
        self::register('claim_samples_inherited', TruthSourceKeys::all(), $agentId);
    }
}
