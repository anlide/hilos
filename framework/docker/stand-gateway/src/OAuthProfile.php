<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use Hilos\Constants\HttpConstants;

/**
 * OAuthProfile - which provider the stand's OAuth emulator is being, and every difference between them (HIL-923).
 *
 * One emulator answers for every provider, because the protocol is one: a consent screen, a code
 * exchanged for a token, a userinfo read with that token. What differs is the wrapping - the field
 * names of userinfo, the shape of a refusal, the way "this account has no email" is said, whether a
 * User-Agent is demanded - and all of it lives here, in the matches below. No other file of the
 * resident takes the profile apart, so a third provider is one case and its arms.
 *
 * The profiles are written from the LIVE providers rather than copied from the framework's own
 * recipe (framework/backend/Auth/OAuth/OAuthProviderPreset.php): an emulator repeating the recipe
 * could not catch a mistake IN the recipe, and such a mistake is exactly what HIL-573 was. What was
 * measured against the real servers on 2026-09-17 is marked `measured` below; the rest is taken
 * from the provider's own documentation, and one refusal GitHub does not document is marked as this
 * stand's own wording.
 *
 * What it deliberately does not model: avatars and the rest of userinfo, Google's `id_token` and
 * `expires_in`, refresh tokens, revocation, and "consent already given, do not ask again". The
 * house rule is not to fake what the framework never touches, because someone then leans on it.
 */
enum OAuthProfile: string
{
    case GITHUB = 'github';

    case GOOGLE = 'google';

    /** Token type GitHub names in its answer, lowercase as the real one writes it. */
    private const string GITHUB_TOKEN_TYPE = 'bearer';

    /** Token type Google names in its answer. */
    private const string GOOGLE_TOKEN_TYPE = 'Bearer';

    /** Media type GitHub answers a token exchange with when the caller asked for no JSON. */
    private const string CONTENT_TYPE_FORM = 'application/x-www-form-urlencoded';

    /** What api.github.com answers a call carrying no User-Agent header (measured 2026-09-17). */
    private const string GITHUB_NO_USER_AGENT =
        'Request forbidden by administrative rules. Please make sure your request has a User-Agent header '
        . '(https://docs.github.com/en/rest/using-the-rest-api/troubleshooting-the-rest-api#user-agent-required). '
        . 'Check https://developer.github.com for other possible causes.';

    /** Where GitHub points a caller whose token exchange it refused. */
    private const string GITHUB_TOKEN_ERRORS_URL =
        'https://docs.github.com/apps/managing-oauth-apps/troubleshooting-oauth-app-access-token-request-errors/';

    /**
     * Heading the consent screen carries.
     *
     * @return string Provider's name as a person reads it
     */
    public function title(): string
    {
        return match ($this) {
            self::GITHUB => 'GitHub',
            self::GOOGLE => 'Google',
        };
    }

    /**
     * The userinfo body this provider answers for one account.
     *
     * The two shapes differ in more than their field names, and the difference is the point: GitHub
     * carries its id as a JSON number and keeps a withheld field as an explicit null, while Google
     * carries its id as a string and LEAVES THE KEY OUT. "This account has no email" therefore looks
     * unlike itself on the two providers, which is the case HIL-573 was found in.
     *
     * @param OAuthAccount $account Account the token was issued for
     * @return array<string, mixed> Userinfo body in this provider's shape
     */
    public function userInfo(OAuthAccount $account): array
    {
        return match ($this) {
            self::GITHUB => [
                'login' => $account->login,
                'id' => (int)$account->subject,
                'name' => $account->name,
                'email' => $account->email,
            ],
            self::GOOGLE => array_filter(
                [
                    'sub' => $account->subject,
                    'name' => $account->name,
                    'email' => $account->email,
                ],
                static fn(?string $value): bool => $value !== null,
            ),
        };
    }

    /**
     * Whether this provider refuses a userinfo call that carried no User-Agent header.
     *
     * GitHub does, which is not a detail: until HIL-924 the framework sent no User-Agent at all,
     * and a login through GitHub failed in production on exactly this step (measured 2026-09-17).
     * The emulator demands the header for that reason - a stand that did not would keep answering
     * a call the real provider refuses, and the fix would have nothing to prove itself against.
     *
     * @return bool True when the header is mandatory
     */
    public function requiresUserAgent(): bool
    {
        return match ($this) {
            self::GITHUB => true,
            self::GOOGLE => false,
        };
    }

    /**
     * How a provider refuses a userinfo call carrying no User-Agent header.
     *
     * One body and no match, because only GitHub demands the header ({@see requiresUserAgent()}) and
     * a second arm would be a refusal no provider ever gives. Plain text and not JSON: the real
     * answer is an HTML-flavored sentence, and a caller parsing it as JSON is a caller that would
     * misread production too.
     *
     * @return array<string, mixed> Refusal response the router sends as is
     */
    public function userAgentRefusal(): array
    {
        return self::response(self::GITHUB_NO_USER_AGENT, HttpConstants::CONTENT_TYPE_TEXT, HttpConstants::HTTP_FORBIDDEN);
    }

    /**
     * How this provider refuses a userinfo call whose token it does not accept (both measured 2026-09-17).
     *
     * @return array<string, mixed> Refusal response the router sends as is
     */
    public function userInfoUnauthorized(): array
    {
        return StandGatewayTlsServer::json(
            match ($this) {
                self::GITHUB => [
                    'message' => 'Bad credentials',
                    'documentation_url' => 'https://docs.github.com/rest',
                    'status' => (string)HttpConstants::HTTP_UNAUTHORIZED,
                ],
                self::GOOGLE => [
                    'error' => 'invalid_request',
                    'error_description' => 'Invalid Credentials',
                ],
            },
            HttpConstants::HTTP_UNAUTHORIZED,
        );
    }

    /**
     * How this provider answers a token exchange it accepted.
     *
     * GitHub answers a FORM unless the caller asked for JSON, which is why the flag is here at all:
     * the framework does ask (its OAuth client sends `Accept: application/json`), so the emulator
     * catches the day it stops asking rather than passing it through. Google answers JSON always.
     *
     * @param string $accessToken Token that was issued
     * @param string $scope Scope the code was issued for
     * @param bool $wantsJson Whether the caller's Accept asked for JSON
     * @return array<string, mixed> Payload the router answers as JSON, or a response it sends as is
     */
    public function tokenSuccess(string $accessToken, string $scope, bool $wantsJson): array
    {
        return match ($this) {
            self::GITHUB => self::githubTokenAnswer([
                'access_token' => $accessToken,
                'scope' => $scope,
                'token_type' => self::GITHUB_TOKEN_TYPE,
            ], $wantsJson),
            self::GOOGLE => [
                'access_token' => $accessToken,
                'scope' => $scope,
                'token_type' => self::GOOGLE_TOKEN_TYPE,
            ],
        };
    }

    /**
     * How this provider refuses a token exchange.
     *
     * The two providers disagree about what a refusal even is. GitHub answers 200 and puts the
     * refusal where the token would have been, in the same envelope the success uses - so the flag
     * belongs here too. Google answers a status of its own per refusal.
     *
     * @param OAuthTokenError $error Refusal to express
     * @param bool $wantsJson Whether the caller's Accept asked for JSON
     * @return array<string, mixed> Payload the router answers as JSON, or a response it sends as is
     */
    public function tokenRefusal(OAuthTokenError $error, bool $wantsJson): array
    {
        return match ($this) {
            self::GITHUB => self::githubTokenAnswer(self::githubTokenRefusal($error), $wantsJson),
            self::GOOGLE => StandGatewayTlsServer::json(
                self::googleTokenRefusal($error),
                $error === OAuthTokenError::BAD_CLIENT ? HttpConstants::HTTP_UNAUTHORIZED : HttpConstants::HTTP_BAD_REQUEST,
            ),
        };
    }

    /**
     * What this provider adds to the callback address when the person refused consent.
     *
     * @return array<string, string> Query parameters of the refusing redirect
     */
    public function denyQuery(): array
    {
        return match ($this) {
            self::GITHUB => [
                'error' => 'access_denied',
                'error_description' => 'The user has denied your application access.',
                'error_uri' => 'https://docs.github.com/apps/managing-oauth-apps/'
                    . 'troubleshooting-authorization-request-errors/#access-denied',
            ],
            self::GOOGLE => ['error' => 'access_denied'],
        };
    }

    /**
     * Wraps a GitHub token answer, success or refusal, in the envelope the caller asked for.
     *
     * @param array<string, string> $payload Body fields
     * @param bool $wantsJson Whether the caller's Accept asked for JSON
     * @return array<string, mixed> Payload the router answers as JSON, or a form response it sends as is
     */
    private static function githubTokenAnswer(array $payload, bool $wantsJson): array
    {
        if ($wantsJson) {
            return $payload;
        }

        return self::response(http_build_query($payload), self::CONTENT_TYPE_FORM, HttpConstants::HTTP_OK);
    }

    /**
     * GitHub's wording for one token refusal.
     *
     * Three of the four are documented by GitHub. `UNSUPPORTED_GRANT` is not - GitHub does not say
     * what it answers a wrong `grant_type` - so the wording below is THIS STAND'S, said out loud
     * here rather than left to look measured.
     *
     * @param OAuthTokenError $error Refusal to express
     * @return array<string, string> Body fields of the refusal
     */
    private static function githubTokenRefusal(OAuthTokenError $error): array
    {
        return match ($error) {
            OAuthTokenError::BAD_CODE => [
                'error' => 'bad_verification_code',
                'error_description' => 'The code passed is incorrect or expired.',
                'error_uri' => self::GITHUB_TOKEN_ERRORS_URL . '#bad-verification-code',
            ],
            OAuthTokenError::BAD_CLIENT => [
                'error' => 'incorrect_client_credentials',
                'error_description' => 'The client_id and/or client_secret passed are incorrect.',
                'error_uri' => self::GITHUB_TOKEN_ERRORS_URL . '#incorrect-client-credentials',
            ],
            OAuthTokenError::REDIRECT_MISMATCH => [
                'error' => 'redirect_uri_mismatch',
                'error_description' => 'The redirect_uri MUST match the registered callback URL for this application.',
                'error_uri' => self::GITHUB_TOKEN_ERRORS_URL . '#redirect-uri-mismatch',
            ],
            OAuthTokenError::UNSUPPORTED_GRANT => [
                'error' => 'unsupported_grant_type',
                'error_description' => 'The grant_type must be authorization_code.',
            ],
        };
    }

    /**
     * Google's wording for one token refusal; the `invalid_client` body was measured 2026-09-17.
     *
     * @param OAuthTokenError $error Refusal to express
     * @return array<string, string> Body fields of the refusal
     */
    private static function googleTokenRefusal(OAuthTokenError $error): array
    {
        return match ($error) {
            OAuthTokenError::BAD_CODE => ['error' => 'invalid_grant', 'error_description' => 'Bad Request'],
            OAuthTokenError::BAD_CLIENT => [
                'error' => 'invalid_client',
                'error_description' => 'The OAuth client was not found.',
            ],
            OAuthTokenError::REDIRECT_MISMATCH => ['error' => 'redirect_uri_mismatch', 'error_description' => 'Bad Request'],
            OAuthTokenError::UNSUPPORTED_GRANT => ['error' => 'unsupported_grant_type', 'error_description' => 'Bad Request'],
        };
    }

    /**
     * Builds an answer that is not JSON.
     *
     * @param string $body Response body
     * @param string $contentType Media type of the body
     * @param int $status HTTP status
     * @return array{status: int, headers: array<string, string>, body: string} Response the router sends as is
     */
    private static function response(string $body, string $contentType, int $status): array
    {
        return [
            HttpConstants::RESPONSE_KEY_STATUS => $status,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => $contentType],
            HttpConstants::RESPONSE_KEY_BODY => $body,
        ];
    }
}
