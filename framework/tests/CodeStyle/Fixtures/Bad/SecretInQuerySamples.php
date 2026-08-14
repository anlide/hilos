<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Hilos\Constants\HilosHttpHeaders;
use Hilos\Core\Http\RequestQueryParams;

/**
 * Deliberately broken sample: every read below names a query parameter the rule's list
 * does not hold, so SECRET-IN-QUERY must report each one - whether the name is written
 * as a literal or as somebody's constant, and whichever of the four readers takes it.
 */
final class SecretInQuerySamples
{
    /**
     * @param RequestQueryParams $queryParams Query params of the request url
     * @return array<int, mixed> Values pulled out of a url that should not carry them
     */
    public function read(RequestQueryParams $queryParams): array
    {
        return [
            $queryParams->getString(HilosHttpHeaders::HILOS_SESSION_TOKEN),
            $queryParams->requireString('token'),
            $queryParams->requireStringMatching('code', '/\A\d{6}\z/', 'six digits'),
            $queryParams->has('invite'),
        ];
    }
}
