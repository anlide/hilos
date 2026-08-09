<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Core\Router;

/**
 * Deliberately broken sample: the path repeats the `Core/Router` segments of the
 * checked zone, so every empty-string fallback below must be reported by
 * EMPTY-STRING-SENTINEL. Both spellings of the literal count.
 *
 * @property-read ?string $fallbackPage Route the caller may not have declared
 */
final class EmptySentinel
{
    /**
     * @param array<string, string> $routes Route map keyed by page name
     * @param string $page Page name to resolve
     * @return array<int, string> Whatever the resolution produced
     */
    public function resolve(array $routes, string $page): array
    {
        $agentType = $routes[$page] ?? '';
        $group = $routes[$page] ?? "";

        return [$agentType, $group, $this->fallbackPage ?? ''];
    }
}
