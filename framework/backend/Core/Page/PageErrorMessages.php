<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\HttpConstants;

/** Public page-refusal wording, independent of exception diagnostics. */
final class PageErrorMessages
{
    /** Ready line a live deletion may add under a 404. Ordinary refusals omit it. */
    public const string RESOURCE_DELETED_MESSAGE = 'It was deleted just now.';

    /**
     * @param int $httpCode HTTP status of the refusal
     * @return PageErrorText Public wording, including the fallback for an unmapped status
     */
    public static function forStatus(int $httpCode): PageErrorText
    {
        return match ($httpCode) {
            HttpConstants::HTTP_BAD_REQUEST => new PageErrorText('Bad Request', 'The request could not be understood.'),
            HttpConstants::HTTP_UNAUTHORIZED => new PageErrorText('Unauthorized', 'Authentication is required.'),
            HttpConstants::HTTP_FORBIDDEN => new PageErrorText('Forbidden', 'You do not have access to this page.'),
            HttpConstants::HTTP_NOT_FOUND => new PageErrorText('Page Not Found', 'The page you are looking for does not exist.'),
            HttpConstants::HTTP_INTERNAL_ERROR => new PageErrorText('Internal Server Error', 'An unexpected error occurred.'),
            HttpConstants::HTTP_BAD_GATEWAY => new PageErrorText('Bad Gateway', 'The server received an invalid response.'),
            HttpConstants::HTTP_SERVICE_UNAVAILABLE => new PageErrorText('Service Unavailable', 'The service is temporarily unavailable.'),
            HttpConstants::HTTP_GATEWAY_TIMEOUT => new PageErrorText('Gateway Timeout', 'The gateway timed out.'),
            default => new PageErrorText('Error', 'An error occurred.'),
        };
    }
}
