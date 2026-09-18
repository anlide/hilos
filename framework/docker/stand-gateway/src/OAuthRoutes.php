<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use Hilos\Constants\HttpConstants;
use Hilos\Utils\Helpers\HttpHeaderHelper;
use Random\RandomException;

/**
 * OAuthRoutes - the OAuth provider the stand pretends to be (HIL-923).
 *
 * The provider's whole half: a consent screen a browser really opens, a code really exchanged for
 * a token over HTTPS, and a userinfo really read with that token. Before it, the stand's provider
 * was an in-process stub that bounced the browser straight back to the callback with a canned code,
 * so twelve closed leaves of OAuth were checked over a transport that did not exist - and the one
 * defect found in that layer was found on a live provider rather than by a test.
 *
 * ONE emulator for every provider, because the protocol is one; the differences are a profile in
 * the path and live entirely in {@see OAuthProfile}. A third provider is a case there, not a file
 * here.
 *
 * The PERSON at that screen is not here, and that is a decision rather than an omission: waiting
 * for the provider's window, pressing a button in it and closing it can only be done by whoever is
 * in the browser, and the gateway has no hands there. The person lives in the spec's own set
 * (demo/chat/tests/e2e/helpers/oauth-user.ts); a server-side half of that person could only
 * drive the screen through a script on the page itself, and then the button would be pressed by
 * the page rather than by a person - exactly what this leaf exists to stop pretending.
 *
 * The test half of the provider declares nothing but the provider's WORLD: which accounts exist
 * over there (a copy of /telegram/test/reachable). Nothing about a login in progress is declared,
 * so there is no race between the window opening and the arrangement being made.
 *
 * What it deliberately does not do: refuse a call with a 500, go silent, cut its answer or hold the
 * connection. Those are the house's levers and they already work on both provider routes of this
 * resident (HIL-922), keyed by the account the call is about.
 */
final class OAuthRoutes implements GatewayResident
{
    /** Channel name, which is also the prefix every route of this resident lives under. */
    public const string CHANNEL = 'oauth';

    /** Route the browser is sent to, and the form on it posts back to. */
    private const string PATH_AUTHORIZE = '/authorize';

    /** Route the daemon exchanges a code at. */
    private const string PATH_TOKEN = '/token';

    /** Route the daemon reads the account at. */
    private const string PATH_USERINFO = '/userinfo';

    /** Route a spec declares an account of a provider's world at. */
    private const string PATH_TEST_ACCOUNT = '/oauth/test/account';

    /** Request field naming the client that asks for access. */
    private const string FIELD_CLIENT_ID = 'client_id';

    /** Request field carrying the client's secret. */
    private const string FIELD_CLIENT_SECRET = 'client_secret';

    /** Request field carrying the address the browser is sent back to. */
    private const string FIELD_REDIRECT_URI = 'redirect_uri';

    /** Request field naming what the client asks the provider for. */
    private const string FIELD_RESPONSE_TYPE = 'response_type';

    /** Request field carrying the access being asked for. */
    private const string FIELD_SCOPE = 'scope';

    /** Request field carrying the client's own opaque string, which the provider only carries back. */
    private const string FIELD_STATE = 'state';

    /** Request field carrying the authorization code. */
    private const string FIELD_CODE = 'code';

    /** Request field naming which exchange the client is making. */
    private const string FIELD_GRANT_TYPE = 'grant_type';

    /** Form field carrying what the person pressed. */
    private const string FIELD_DECISION = 'decision';

    /** Form field carrying the account the person chose. */
    private const string FIELD_SUBJECT = 'subject';

    /** The only response type an authorization request may ask for here. */
    private const string RESPONSE_TYPE_CODE = 'code';

    /** The only grant type the exchange accepts. */
    private const string GRANT_TYPE_AUTHORIZATION_CODE = 'authorization_code';

    /** Decision of a person who granted access. */
    private const string DECISION_CONFIRM = 'confirm';

    /** Decision of a person who refused access. */
    private const string DECISION_DENY = 'deny';

    /** How long an authorization code stays good, which is GitHub's own ten minutes. */
    private const int CODE_TTL_SECONDS = 600;

    /** Length of the authorization code, in bytes before it is written out in hex. */
    private const int CODE_BYTES = 16;

    /** Length of the access token, in bytes before it is written out in hex. */
    private const int TOKEN_BYTES = 20;

    /** Schemes a callback address may have; a provider redirects a browser and nothing else. */
    private const array CALLBACK_SCHEMES = ['http', 'https'];

    /** What a login handle is made of when the declaration named none. */
    private const string LOGIN_PREFIX = 'user';

    /** Request header the exchange reads the wanted envelope from. */
    private const string HEADER_ACCEPT = 'Accept';

    /** Request header userinfo reads the access token from. */
    private const string HEADER_AUTHORIZATION = 'Authorization';

    /**
     * Response header naming where a browser is sent next.
     *
     * Private here, along with the status below, because the framework's HttpConstants carries
     * neither and this leaf does not touch the framework. One visible consequence: the status line
     * of the redirect reads `HTTP/1.1 302 Unknown`, since the framework's table of status texts has
     * no 302 in it. A browser does not care, and the table is not to be "fixed" for the sake of it.
     */
    private const string HEADER_LOCATION = 'Location';

    /** Status a provider sends a browser back to the client with. */
    private const int STATUS_FOUND = 302;

    /** Test id of the consent screen itself, which is how the spec's person knows the window is up. */
    private const string ID_CONSENT = 'oauth-consent';

    /** Test id of the line naming who asks for access. */
    private const string ID_CLIENT = 'oauth-client';

    /** Test id of the line naming what is being asked for. */
    private const string ID_SCOPE = 'oauth-scope';

    /** Test id prefix of one account's radio button; the account's id follows it. */
    private const string ID_ACCOUNT_PREFIX = 'oauth-account-';

    /** Test id of the line saying no account has been declared at this provider. */
    private const string ID_ACCOUNTS_EMPTY = 'oauth-accounts-empty';

    /** Test id of the button that grants access. */
    private const string ID_CONFIRM = 'oauth-confirm';

    /** Test id of the button that refuses access. */
    private const string ID_DENY = 'oauth-deny';

    /** Test id of the line saying why the screen refused to be a screen. */
    private const string ID_ERROR = 'oauth-error';

    /**
     * Registers the provider's four routes for every profile, and its one test route.
     *
     * The consent screen is registered as a page on both methods: a browser opens it and a browser
     * posts its form, so there is no value of the call a spec coined and nothing to key a
     * declaration by. The exchange and the userinfo read are provider routes, and both are keyed by
     * the ACCOUNT the call is about - the derived key HIL-922 promised, worked out from the code on
     * one route and from the token on the other.
     *
     * A trap worth knowing: the key function runs BEFORE the handler, on every call of the route,
     * so it must not spend anything. That is why it peeks at the code rather than taking it, and
     * why the handler is the only place a code is spent.
     *
     * @param GatewayRoutes $routes Routes of the connection being accepted
     */
    public function register(GatewayRoutes $routes): void
    {
        foreach (OAuthProfile::cases() as $profile) {
            $prefix = HttpConstants::PATH_ROOT . self::CHANNEL . HttpConstants::PATH_ROOT . $profile->value;

            $routes->page(
                HttpConstants::METHOD_GET,
                $prefix . self::PATH_AUTHORIZE,
                fn(array $fields): array => $this->consentScreen($profile, $fields),
            );
            $routes->page(
                HttpConstants::METHOD_POST,
                $prefix . self::PATH_AUTHORIZE,
                fn(array $fields): array => $this->consentDecision($profile, $fields),
            );
            $routes->provider(
                HttpConstants::METHOD_POST,
                $prefix . self::PATH_TOKEN,
                fn(array $fields, array $headers): array => $this->token($profile, $fields, $headers),
                static fn(array $fields): string => Store::peekOAuthCode(self::field($fields, self::FIELD_CODE))?->subject ?? '',
            );
            $routes->provider(
                HttpConstants::METHOD_GET,
                $prefix . self::PATH_USERINFO,
                fn(array $fields, array $headers): array => $this->userInfo($profile, $fields, $headers),
                static fn(array $fields, array $headers): string => Store::oauthToken(self::bearer($headers))?->subject ?? '',
            );
        }

        // Test side: the world of the provider, which no spec can arrange any other way.
        $routes->test(HttpConstants::METHOD_POST, self::PATH_TEST_ACCOUNT, $this->testAccount(...));
    }

    /**
     * Shows the consent screen, or refuses to be one.
     *
     * The request is checked the way the provider would check it, and a bad one is answered with a
     * PAGE rather than with a redirect: an authorization request whose callback address is junk has
     * nowhere to be redirected to, which is exactly what a real provider does about it.
     *
     * @param OAuthProfile $profile Provider being played
     * @param array<string, mixed> $fields Request fields, the query merged in
     * @return array<string, mixed> The screen, or a 400 naming the parameter
     */
    private function consentScreen(OAuthProfile $profile, array $fields): array
    {
        $invalid = self::invalidParameter($fields, true);
        if ($invalid !== null) {
            return self::refusal($profile, $invalid);
        }

        return self::html(self::consentBody($profile, $fields), HttpConstants::HTTP_OK);
    }

    /**
     * Acts on the button the person pressed.
     *
     * A closed window is not here and must not be: nothing is sent when a person walks away, so the
     * gateway sees no call at all. What that does to the product is the business of the leaf where
     * the product takes part.
     *
     * The callback address is checked again, because this call carries its own copy of it and a
     * refusing redirect goes to it too. The response type is not: it belongs to the request the
     * browser arrived with, and the form does not carry it back.
     *
     * @param OAuthProfile $profile Provider being played
     * @param array<string, mixed> $fields Form fields
     * @return array<string, mixed> The redirect back to the client, or a 400 naming the parameter
     * @throws RandomException When the platform CSPRNG cannot produce a code
     */
    private function consentDecision(OAuthProfile $profile, array $fields): array
    {
        $invalid = self::invalidParameter($fields, false);
        if ($invalid !== null) {
            return self::refusal($profile, $invalid);
        }

        $redirectUri = self::field($fields, self::FIELD_REDIRECT_URI);
        $state = self::field($fields, self::FIELD_STATE);
        $decision = self::field($fields, self::FIELD_DECISION);

        if ($decision === self::DECISION_DENY) {
            return self::redirect(self::callback($redirectUri, $profile->denyQuery(), $state));
        }

        if ($decision !== self::DECISION_CONFIRM) {
            return self::refusal($profile, self::FIELD_DECISION);
        }

        $subject = self::field($fields, self::FIELD_SUBJECT);
        if (Store::oauthAccount($profile->value, $subject) === null) {
            return self::refusal($profile, self::FIELD_SUBJECT);
        }

        $code = bin2hex(random_bytes(self::CODE_BYTES));
        Store::issueOAuthCode($code, new OAuthGrant(
            profile: $profile->value,
            subject: $subject,
            clientId: self::field($fields, self::FIELD_CLIENT_ID),
            redirectUri: $redirectUri,
            scope: self::field($fields, self::FIELD_SCOPE),
            issuedAt: time(),
        ));

        return self::redirect(self::callback($redirectUri, [self::FIELD_CODE => $code], $state));
    }

    /**
     * Exchanges an authorization code for an access token.
     *
     * The checks run in one order and the FIRST one that does not match is the one named: the grant
     * type, then the code, then the client, then the callback address. The likeliest mistake a spec
     * makes is the code, and a complaint about the client on top of a spoilt code would send the
     * reader looking in the wrong place.
     *
     * The code is spent the moment it is recognized, refused exchange or not: it is good once, and
     * the second exchange of the same code gets the provider's refusal because there is nothing
     * left to find. The right it carried moves onto the token.
     *
     * The client secret is checked for being there and nothing more. A stand cannot know the real
     * one, but a daemon that forgot its credentials has to fail HERE rather than in production -
     * the same reason the Telegram half demands a bearer.
     *
     * @param OAuthProfile $profile Provider being played
     * @param array<string, mixed> $fields Request fields
     * @param array<string, string> $headers Request headers, names in lowercase
     * @return array<string, mixed> The token, or this provider's refusal
     * @throws RandomException When the platform CSPRNG cannot produce a token
     */
    private function token(OAuthProfile $profile, array $fields, array $headers): array
    {
        $accept = HttpHeaderHelper::get($headers, self::HEADER_ACCEPT) ?? '';
        $wantsJson = str_contains($accept, HttpConstants::CONTENT_TYPE_JSON);

        if (self::field($fields, self::FIELD_GRANT_TYPE) !== self::GRANT_TYPE_AUTHORIZATION_CODE) {
            return $profile->tokenRefusal(OAuthTokenError::UNSUPPORTED_GRANT, $wantsJson);
        }

        $grant = Store::takeOAuthCode(self::field($fields, self::FIELD_CODE));
        if ($grant === null || $grant->profile !== $profile->value || time() - $grant->issuedAt > self::CODE_TTL_SECONDS) {
            return $profile->tokenRefusal(OAuthTokenError::BAD_CODE, $wantsJson);
        }

        if ($grant->clientId !== self::field($fields, self::FIELD_CLIENT_ID) || self::field($fields, self::FIELD_CLIENT_SECRET) === '') {
            return $profile->tokenRefusal(OAuthTokenError::BAD_CLIENT, $wantsJson);
        }

        if ($grant->redirectUri !== self::field($fields, self::FIELD_REDIRECT_URI)) {
            return $profile->tokenRefusal(OAuthTokenError::REDIRECT_MISMATCH, $wantsJson);
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        Store::issueOAuthToken($token, $grant);

        return $profile->tokenSuccess($token, $grant->scope, $wantsJson);
    }

    /**
     * Answers who the token belongs to.
     *
     * The header a live provider demands is demanded first, because a call missing it never reaches
     * the token on the real one either. Then the token, which has to be this profile's: the worlds
     * of two providers are separate, and a token minted at one is nothing at the other.
     *
     * The account is read from the store LIVE rather than from a copy taken when the code was
     * issued. A spec may re-declare an account between the exchange and this call, and seeing the
     * new values is what proves the answer is read rather than replayed.
     *
     * @param OAuthProfile $profile Provider being played
     * @param array<string, mixed> $fields Request fields (unused; the token rides in a header)
     * @param array<string, string> $headers Request headers, names in lowercase
     * @return array<string, mixed> The account in this provider's shape, or its refusal
     */
    private function userInfo(OAuthProfile $profile, array $fields, array $headers): array
    {
        if ($profile->requiresUserAgent() && (HttpHeaderHelper::get($headers, HttpConstants::HEADER_USER_AGENT) ?? '') === '') {
            return $profile->userAgentRefusal();
        }

        $grant = Store::oauthToken(self::bearer($headers));
        if ($grant === null || $grant->profile !== $profile->value) {
            return $profile->userInfoUnauthorized();
        }

        $account = Store::oauthAccount($grant->profile, $grant->subject);
        if ($account === null) {
            return $profile->userInfoUnauthorized();
        }

        return $profile->userInfo($account);
    }

    /**
     * Test route: declare, or re-declare, one account of a provider's world.
     *
     * The checks run in the order a declaration is read - an unknown key, the profile, the id, then
     * the rest - and a refusal is a 400 with the code, so the spec fails where the declaration was
     * made rather than later on a screen with nobody to choose. The same shape as POST
     * /test/behavior, deliberately: a spec learns one way of being refused.
     *
     * @param array<string, mixed> $fields Request fields
     * @return array<string, mixed> Acknowledgement, or a 400 naming the refusal
     */
    private function testAccount(array $fields): array
    {
        try {
            if (array_diff(array_keys($fields), OAuthAccount::FIELDS) !== []) {
                throw new InvalidOAuthAccountException(InvalidOAuthAccountException::FIELD_UNKNOWN);
            }

            $profile = OAuthProfile::tryFrom(self::field($fields, OAuthAccount::FIELD_PROFILE));
            if ($profile === null) {
                throw new InvalidOAuthAccountException(InvalidOAuthAccountException::PROFILE_UNKNOWN);
            }

            $subject = self::field($fields, OAuthAccount::FIELD_SUBJECT);
            if (preg_match('/^\d+$/', $subject) !== 1) {
                throw new InvalidOAuthAccountException(InvalidOAuthAccountException::SUBJECT_REQUIRED);
            }

            Store::putOAuthAccount($profile->value, new OAuthAccount(
                subject: $subject,
                login: self::login($fields, $subject),
                name: self::optionalField($fields, OAuthAccount::FIELD_NAME),
                email: self::optionalField($fields, OAuthAccount::FIELD_EMAIL),
            ));
        } catch (InvalidOAuthAccountException $refusal) {
            return StandGatewayTlsServer::json(['ok' => false, 'error' => $refusal->error], HttpConstants::HTTP_BAD_REQUEST);
        }

        return ['ok' => true];
    }

    /**
     * Names the first parameter of an authorization request this provider would not accept.
     *
     * @param array<string, mixed> $fields Request fields
     * @param bool $withResponseType Whether the response type is part of this request
     * @return ?string Name of the offending parameter, null when the request is well formed
     */
    private static function invalidParameter(array $fields, bool $withResponseType): ?string
    {
        if (self::field($fields, self::FIELD_CLIENT_ID) === '') {
            return self::FIELD_CLIENT_ID;
        }

        if (!self::isCallbackAddress(self::field($fields, self::FIELD_REDIRECT_URI))) {
            return self::FIELD_REDIRECT_URI;
        }

        if ($withResponseType && self::field($fields, self::FIELD_RESPONSE_TYPE) !== self::RESPONSE_TYPE_CODE) {
            return self::FIELD_RESPONSE_TYPE;
        }

        return null;
    }

    /**
     * Whether an address is one a browser can be sent back to.
     *
     * @param string $address Address as the request carried it
     * @return bool True when the address is absolute and names a host over http or https
     */
    private static function isCallbackAddress(string $address): bool
    {
        $parts = parse_url($address);
        if (!is_array($parts)) {
            return false;
        }

        return in_array($parts['scheme'] ?? '', self::CALLBACK_SCHEMES, true) && ($parts['host'] ?? '') !== '';
    }

    /**
     * Builds the address the browser is sent back to.
     *
     * The state is carried back exactly as it arrived and only when there was one: it is the
     * client's own string, and whether it is signed is the daemon's business rather than the
     * provider's.
     *
     * @param string $redirectUri Callback address the request carried
     * @param array<string, string> $query What the provider has to say - a code, or a refusal
     * @param string $state Client's opaque string, empty when the request carried none
     * @return string Address for the Location header
     */
    private static function callback(string $redirectUri, array $query, string $state): string
    {
        if ($state !== '') {
            $query[self::FIELD_STATE] = $state;
        }

        $separator = str_contains($redirectUri, HttpConstants::QUERY_STRING_SEPARATOR)
            ? HttpConstants::QUERY_PAIR_SEPARATOR
            : HttpConstants::QUERY_STRING_SEPARATOR;

        return $redirectUri . $separator . http_build_query($query);
    }

    /**
     * The login handle of a declared account, filled in when the declaration named none.
     *
     * @param array<string, mixed> $fields Request fields
     * @param string $subject Id the account was declared under
     * @return string Login handle, never empty
     * @throws InvalidOAuthAccountException When the login is present but is not a string
     */
    private static function login(array $fields, string $subject): string
    {
        $login = self::optionalField($fields, OAuthAccount::FIELD_LOGIN);

        return $login === null || $login === '' ? self::LOGIN_PREFIX . $subject : $login;
    }

    /**
     * Reads a field a declaration may leave out; left out means the account HAS none.
     *
     * @param array<string, mixed> $fields Request fields
     * @param string $name Field to read
     * @return ?string Value as declared, null when the declaration left it out
     * @throws InvalidOAuthAccountException When the field is present but is not a string
     */
    private static function optionalField(array $fields, string $name): ?string
    {
        $value = $fields[$name] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidOAuthAccountException(InvalidOAuthAccountException::FIELD_INVALID);
        }

        return $value;
    }

    /**
     * Reads one field of a request as the string it is supposed to be.
     *
     * @param array<string, mixed> $fields Request fields
     * @param string $name Field to read
     * @return string Field value, empty when it is absent or is not a string
     */
    private static function field(array $fields, string $name): string
    {
        $value = $fields[$name] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Reads the access token out of the Authorization header.
     *
     * @param array<string, string> $headers Request headers, names in lowercase
     * @return string Token the call carried, empty when it carried no bearer
     */
    private static function bearer(array $headers): string
    {
        $header = HttpHeaderHelper::get($headers, self::HEADER_AUTHORIZATION) ?? '';

        return preg_match('/^Bearer\s+(\S+)/', $header, $matches) === 1 ? $matches[1] : '';
    }

    /**
     * The consent screen: who is asking, what for, and which account to answer with.
     *
     * Deliberately plain markup and no styling at all. What this page has to be is operable and
     * legible to the person in the spec, and every line of decoration would be a line nobody's
     * product ever renders.
     *
     * @param OAuthProfile $profile Provider being played
     * @param array<string, mixed> $fields Request fields, the query merged in
     * @return string Page body
     */
    private static function consentBody(OAuthProfile $profile, array $fields): string
    {
        $carried = '';
        foreach ([self::FIELD_CLIENT_ID, self::FIELD_REDIRECT_URI, self::FIELD_SCOPE, self::FIELD_STATE] as $name) {
            $carried .= '<input type="hidden" name="' . $name . '" value="' . self::escape(self::field($fields, $name)) . '">';
        }

        $accounts = Store::oauthAccounts($profile->value);
        $choices = $accounts === []
            ? '<p data-id="' . self::ID_ACCOUNTS_EMPTY . '">No account is declared at this provider.</p>'
            : implode('', array_map(self::choice(...), $accounts));

        $path = HttpConstants::PATH_ROOT . self::CHANNEL . HttpConstants::PATH_ROOT . $profile->value . self::PATH_AUTHORIZE;

        return self::document(
            $profile,
            '<form method="post" action="' . $path . '" data-id="' . self::ID_CONSENT . '">'
            . '<p data-id="' . self::ID_CLIENT . '">' . self::escape(self::field($fields, self::FIELD_CLIENT_ID)) . '</p>'
            . '<p data-id="' . self::ID_SCOPE . '">' . self::escape(self::field($fields, self::FIELD_SCOPE)) . '</p>'
            . $carried
            . $choices
            . '<button type="submit" name="' . self::FIELD_DECISION . '" value="' . self::DECISION_CONFIRM . '"'
            . ' data-id="' . self::ID_CONFIRM . '">Authorize</button>'
            . '<button type="submit" name="' . self::FIELD_DECISION . '" value="' . self::DECISION_DENY . '"'
            . ' data-id="' . self::ID_DENY . '">Cancel</button>'
            . '</form>',
        );
    }

    /**
     * One account of the provider's world, as the screen offers it.
     *
     * @param OAuthAccount $account Declared account
     * @return string Markup of the choice
     */
    private static function choice(OAuthAccount $account): string
    {
        return '<label><input type="radio" name="' . self::FIELD_SUBJECT . '" value="' . self::escape($account->subject) . '"'
            . ' data-id="' . self::ID_ACCOUNT_PREFIX . self::escape($account->subject) . '">'
            . self::escape($account->login . ' (' . ($account->email ?? 'no email') . ')')
            . '</label>';
    }

    /**
     * Refuses to be a consent screen, naming the parameter that is wrong.
     *
     * @param OAuthProfile $profile Provider being played
     * @param string $parameter Name of the offending parameter
     * @return array<string, mixed> Refusal page the router sends as is
     */
    private static function refusal(OAuthProfile $profile, string $parameter): array
    {
        $body = '<p data-id="' . self::ID_ERROR . '">' . self::escape($parameter) . '</p>';

        return self::html(self::document($profile, $body), HttpConstants::HTTP_BAD_REQUEST);
    }

    /**
     * Wraps a screen's content in the page around it.
     *
     * @param OAuthProfile $profile Provider being played
     * @param string $content Body content
     * @return string Whole document
     */
    private static function document(OAuthProfile $profile, string $content): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $profile->title() . '</title></head>'
            . '<body><h1>' . $profile->title() . '</h1>' . $content . '</body></html>';
    }

    /**
     * Makes one value from a request safe to put on a page.
     *
     * @param string $value Value as it arrived
     * @return string Value with its markup neutralized
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Builds a page answer.
     *
     * @param string $body Page body
     * @param int $status HTTP status
     * @return array{status: int, headers: array<string, string>, body: string} Response the router sends as is
     */
    private static function html(string $body, int $status): array
    {
        return [
            HttpConstants::RESPONSE_KEY_STATUS => $status,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_HTML],
            HttpConstants::RESPONSE_KEY_BODY => $body,
        ];
    }

    /**
     * Builds the answer that sends the browser somewhere else.
     *
     * @param string $location Address to send the browser to
     * @return array{status: int, headers: array<string, string>, body: string} Response the router sends as is
     */
    private static function redirect(string $location): array
    {
        return [
            HttpConstants::RESPONSE_KEY_STATUS => self::STATUS_FOUND,
            HttpConstants::RESPONSE_KEY_HEADERS => [self::HEADER_LOCATION => $location],
            HttpConstants::RESPONSE_KEY_BODY => '',
        ];
    }
}
