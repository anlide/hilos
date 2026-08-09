<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Core\Router;

/**
 * Deliberately broken sample: a ternary mints the sentinel exactly the way `??`
 * does, and the short form of the same operator mints it too. The last branch is
 * seeded with a bracketed sub-expression between the `?` and the `:`, so the
 * colon has to be matched to its own ternary rather than to the nearest token.
 */
final class TernaryBranch
{
    /**
     * @param array<string, string> $payload Action payload as the browser sent it
     * @param bool $trusted Whether the caller is allowed to see the raw reason
     * @return array<int, string> Whatever the reading produced
     */
    public function read(array $payload, bool $trusted): array
    {
        $reason = isset($payload['reason']) ? $payload['reason'] : '';
        $code = $payload['code'] ?: "";
        $detail = $trusted ? ($payload['detail'] ?? 'hidden') : '';

        return [$reason, $code, $detail];
    }
}
