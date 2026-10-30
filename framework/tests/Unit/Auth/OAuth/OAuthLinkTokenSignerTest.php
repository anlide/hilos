<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use Hilos\Auth\OAuth\OAuthStateSigner;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the stateless OAuth account-link token signer (HIL-282).
 *
 * The capability the email-collision branch hands the browser: it round-trips its
 * fields, rejects tampering / expiry / empties, and — sharing one app secret with
 * the state signer — stays cryptographically distinct from a state token.
 */
final class OAuthLinkTokenSignerTest extends TestCase
{
    private const string SECRET = 'unit-app-secret';

    public function testIssueRoundTripsProviderSubjectAndEmail(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);

        $token = $signer->issue('oauth:github', '4242', 'user@example.com', 600);
        $data = $signer->verify($token);

        self::assertNotNull($data);
        self::assertSame('oauth:github', $data->provider);
        self::assertSame('4242', $data->subject);
        self::assertSame('user@example.com', $data->email);
        self::assertNull($data->providerName);
    }

    public function testProviderNameWithUnicodeAndSeparatorRoundTripsAndCannotBeChanged(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);
        $token = $signer->issue('oauth:github', '4242', 'user@example.com', 600, '李 | Łukasz 👩‍💻');
        $data = $signer->verify($token);
        self::assertNotNull($data);
        self::assertSame('李 | Łukasz 👩‍💻', $data->providerName);
        [$encoded, $signature] = explode('.', $token);
        $fields = explode('|', base64_decode(strtr($encoded, '-_', '+/')));
        self::assertCount(6, $fields);
        $fields[5] = rtrim(strtr(base64_encode('"Changed"'), '+/', '-_'), '=');
        $changed = rtrim(strtr(base64_encode(implode('|', $fields)), '+/', '-_'), '=');
        self::assertNull($signer->verify($changed . '.' . $signature));
    }

    public function testLegacyFiveFieldTokenStillVerifies(): void
    {
        $token = $this->signedPayload('oauth-link|oauth:github|4242|user@example.com|' . (time() + 600));
        $data = new OAuthLinkTokenSigner(self::SECRET)->verify($token);
        self::assertNotNull($data);
        self::assertSame('oauth:github', $data->provider);
        self::assertSame('4242', $data->subject);
        self::assertSame('user@example.com', $data->email);
        self::assertNull($data->providerName);
    }

    /** @return array<string, array{string}> Invalid sixth fields even with a valid signature */
    public static function malformedNames(): array
    {
        return [
            'empty' => [''], 'bad base64' => ['!'], 'padding' => ['bnVsbA=='], 'not json' => ['bm9wZQ'],
            'number' => ['NDI'], 'boolean' => ['dHJ1ZQ'], 'array' => ['W10'], 'object' => ['e30'],
            'invalid utf8' => [base64_encode("\"\xff\"")], 'extra field' => ['bnVsbA|extra'],
        ];
    }

    /** @param string $encodedName Malformed name representation */
    #[DataProvider('malformedNames')]
    public function testSignedMalformedNameIsRefused(string $encodedName): void
    {
        $token = $this->signedPayload('oauth-link|oauth:github|4242|user@example.com|' . (time() + 600) . '|' . $encodedName);
        self::assertNull(new OAuthLinkTokenSigner(self::SECRET)->verify($token));
    }

    public function testInvalidUtf8NameCannotBeIssued(): void
    {
        $this->expectException(JsonException::class);
        new OAuthLinkTokenSigner(self::SECRET)->issue('oauth:github', '4242', 'user@example.com', 600, "\xff");
    }

    /**
     * @param string $payload Raw delimited fields
     * @return string Independently signed fixture
     */
    private function signedPayload(string $payload): string
    {
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, self::SECRET, true)), '+/', '-_'), '=');

        return $encoded . '.' . $signature;
    }

    public function testVerifyRejectsATamperedSignature(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);

        $token = $signer->issue('oauth:github', '4242', 'user@example.com', 600);

        self::assertNull($signer->verify($token . 'x'));
    }

    public function testVerifyRejectsADifferentSecret(): void
    {
        $token = new OAuthLinkTokenSigner(self::SECRET)->issue('oauth:github', '4242', 'user@example.com', 600);

        self::assertNull(new OAuthLinkTokenSigner('other-secret')->verify($token));
    }

    public function testVerifyRejectsAnExpiredToken(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);

        $token = $signer->issue('oauth:github', '4242', 'user@example.com', -1);

        self::assertNull($signer->verify($token));
    }

    public function testVerifyRejectsAnEmptyEmailField(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);

        $token = $signer->issue('oauth:github', '4242', '', 600);

        self::assertNull($signer->verify($token));
    }

    public function testVerifyRejectsAMalformedToken(): void
    {
        $signer = new OAuthLinkTokenSigner(self::SECRET);

        self::assertNull($signer->verify('not-a-token'));
    }

    /**
     * Domain separation: a state token minted with the same secret is never a valid
     * link token (its payload lacks the link domain tag and field shape).
     */
    public function testVerifyRejectsAStateTokenSharingTheSecret(): void
    {
        $stateToken = new OAuthStateSigner(self::SECRET)->issue('session-token', 600);

        self::assertNull(new OAuthLinkTokenSigner(self::SECRET)->verify($stateToken));
    }
}
