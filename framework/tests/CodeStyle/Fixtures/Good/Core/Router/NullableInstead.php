<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Core\Router;

/**
 * Negative sample inside the checked zone: a missing value stays null, a check for
 * emptiness on an input is not the minting of one, and an optional fragment is
 * rendered by branching on the absence rather than by concatenating an empty
 * piece — the machine judges the branch that hands back `''` wherever it sits.
 */
final class NullableInstead
{
    /**
     * @param array<string, string> $routes Route map keyed by page name
     * @param string $page Page name to resolve
     * @param ?string $index Optional agent index carried by the destination
     * @return array<int, ?string> Resolved route and the key built from it
     */
    public function resolve(array $routes, string $page, ?string $index): array
    {
        $agentType = $routes[$page] ?? null;
        if ($page === '' || $agentType === '') {
            return [null, '?? \'\' quoted here is text, not a fallback'];
        }

        // A fallback of `?? ''` written in a comment is text too.
        return [$agentType, $index === null ? $page : $page . ':' . $index];
    }
}
