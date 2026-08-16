<?php

declare(strict_types=1);

namespace Hilos\Auth\MagicLink;

use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\EnvConstants;

/**
 * MagicLinkUrl - assembles the clickable address a magic-link letter carries (HIL-417).
 *
 * Until now the magic-link token travelled to the mail template bare, because
 * nothing owned the other half of a link: the framework knows no base URL of its
 * own, so the return screen is the project's {@see EnvConstants::HILOS_MAGIC_LINK_URL}.
 * This puts the two together, and it does so as a pure function of the base URL so
 * the assembly can be read and tested without an environment behind it - the
 * env is read one level up, by {@see VerificationService::issue()}, which is also
 * where the token exists.
 *
 * Both params are RFC-3986 encoded, and a base URL that already carries a query
 * keeps it: a return screen is free to be a path, a query-routed page, or a
 * deep-link with its own params, and none of those may be broken by appending.
 */
final class MagicLinkUrl
{
    /** Query param carrying the address the link was issued for. */
    public const string PARAM_EMAIL = 'email';

    /** Query param carrying the issued sign-in token. */
    public const string PARAM_TOKEN = 'token';

    /**
     * Appends the address and its token to the configured return screen.
     *
     * @param string $baseUrl Absolute address of the return screen (HILOS_MAGIC_LINK_URL)
     * @param string $email Normalized address the token was issued for
     * @param string $token Plaintext sign-in token
     * @return string Clickable magic-link URL
     */
    public static function build(string $baseUrl, string $email, string $token): string
    {
        $query = http_build_query(
            [
                self::PARAM_EMAIL => $email,
                self::PARAM_TOKEN => $token,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . $query;
    }
}
