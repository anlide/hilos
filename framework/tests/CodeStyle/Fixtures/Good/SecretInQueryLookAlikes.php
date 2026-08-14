<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use Demo\Chat\Database\Object\Item\EventAttachment as ObjectEventAttachment;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\ProtectedMode\ProtectedModeAdmissionConstants;

/**
 * Look-alikes SECRET-IN-QUERY has to stay silent on: the two names its list holds, read
 * the way the real call sites read them; a reader the rule does not watch; and the same
 * call quoted in a string or written in a comment, where it is text and not a call.
 */
final class SecretInQueryLookAlikes
{
    /**
     * @param RequestQueryParams $queryParams Query params of the request url
     * @return array<int, mixed> Reads the list allows, and spellings that only look like reads
     */
    public function read(RequestQueryParams $queryParams): array
    {
        // $queryParams->getString('token') written in a comment is a mention, not a call
        return [
            $queryParams->getString(ProtectedModeAdmissionConstants::HILOS_PASS_QUERY_PARAM),
            $queryParams->getString(ObjectEventAttachment::id),
            $queryParams->toArray(),
            '$queryParams->requireString(\'token\')',
        ];
    }
}
