<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: every call below stops the process until a nameserver
 * answers, and this file is not in the rule's list, so BLOCKING-RESOLUTION must report
 * each one of the six - whether the builtin is written bare or fully qualified.
 *
 * The last call is the exception that proves where the family ends: a namespaced function
 * of somebody's own, wearing one of the six names. CODE-FQN has something to say about how
 * it is written, and BLOCKING-RESOLUTION must have nothing to say about it at all. It sits
 * here rather than among the look-alikes because those must be clean for every rule.
 */
final class BlockingResolutionSamples
{
    /**
     * @param string $host Name somebody decided to resolve inside a loop
     * @return array<int, mixed> Whatever the resolver hands back, seconds later
     */
    public function resolve(string $host): array
    {
        return [
            gethostbyname($host),
            gethostbynamel($host),
            gethostbyaddr('127.0.0.1'),
            dns_get_record($host),
            \dns_get_mx($host, $weights),
            checkdnsrr($host),
            \Hilos\Tests\CodeStyle\Fixtures\Bad\Resolver\gethostbyname($host),
        ];
    }
}
