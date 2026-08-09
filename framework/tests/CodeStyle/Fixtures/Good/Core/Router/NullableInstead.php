<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Core\Router;

/**
 * Negative sample inside the checked zone: a missing value stays null, a check for
 * emptiness on an input is not the minting of one, and a ternary that renders an
 * optional fragment into a concatenation is the very case the rule leaves to
 * review rather than to the machine.
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
        return [$agentType, $page . ($index !== null ? ':' . $index : '')];
    }
}
