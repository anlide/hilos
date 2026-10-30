<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\OAuthAccountTokenSigner;
use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The first sign-in token is signed, session-bound, expiring, and domain-separated (HIL-1235). */
final class OAuthAccountTokenSignerTest extends TestCase
{
    private const string SECRET = 'unit-app-secret';
    private const string SESSION = 'session-one';

    public function testRoundTripsProviderFactsIncludingUnusualNameAndMissingEmail(): void
    {
        $signer = new OAuthAccountTokenSigner(self::SECRET);
        $token = $signer->issue('oauth:github', 'subject-42', null, 'Łukasz | Developer', self::SESSION, 600);

        $data = $signer->verify($token, self::SESSION);

        self::assertNotNull($data);
        self::assertSame('oauth:github', $data->provider);
        self::assertSame('subject-42', $data->subject);
        self::assertNull($data->email);
        self::assertSame('Łukasz | Developer', $data->displayName);
        self::assertNull($data->providerName);
    }

    /** @return array<string, array{?string}> Optional provider facts */
    public static function providerNames(): array
    {
        return ['absent' => [null], 'unicode and separator' => ['李 | Łukasz 👩‍💻']];
    }

    /** @param ?string $providerName Name independent of the project's display name */
    #[DataProvider('providerNames')]
    public function testProviderNameIsSignedSeparately(?string $providerName): void
    {
        $signer = new OAuthAccountTokenSigner(self::SECRET);
        $token = $signer->issue('oauth:github', '42', null, 'Project Person', self::SESSION, 600, $providerName);
        $data = $signer->verify($token, self::SESSION);
        self::assertNotNull($data);
        self::assertSame('Project Person', $data->displayName);
        self::assertSame($providerName, $data->providerName);

        [$encoded, $signature] = explode('.', $token);
        $fields = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        $fields['providerName'] = 'Changed';
        $changed = rtrim(strtr(base64_encode(json_encode($fields, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        self::assertNull($signer->verify($changed . '.' . $signature, self::SESSION));
    }

    public function testLegacySignedPayloadWithoutProviderNameStillVerifies(): void
    {
        $data = new OAuthAccountTokenSigner(self::SECRET)->verify($this->signedPayload([
            'tag' => 'oauth-account',
            'provider' => 'oauth:github',
            'subject' => '42',
            'email' => null,
            'name' => 'Legacy Project Name',
            'session' => ProtectedModeRuntime::hashSessionToken(self::SESSION),
            'expires' => time() + 600,
        ]), self::SESSION);

        self::assertNotNull($data);
        self::assertSame('Legacy Project Name', $data->displayName);
        self::assertNull($data->providerName);
    }

    public function testSignedPayloadRejectsMistypedProviderName(): void
    {
        $token = $this->signedPayload([
            'tag' => 'oauth-account', 'provider' => 'oauth:github', 'subject' => '42', 'email' => null,
            'name' => 'Project Person', 'session' => ProtectedModeRuntime::hashSessionToken(self::SESSION),
            'expires' => time() + 600, 'providerName' => ['Unexpected'],
        ]);
        self::assertNull(new OAuthAccountTokenSigner(self::SECRET)->verify($token, self::SESSION));
    }

    /**
     * @param array<string, mixed> $fields Exact signed JSON fields
     * @return string Independently signed fixture
     */
    private function signedPayload(array $fields): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($fields, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, self::SECRET, true)), '+/', '-_'), '=');

        return $encoded . '.' . $signature;
    }

    public function testRejectsAnotherSession(): void
    {
        $signer = new OAuthAccountTokenSigner(self::SECRET);
        $token = $signer->issue('oauth:github', '42', 'user@example.test', 'User', self::SESSION, 600);

        self::assertNull($signer->verify($token, 'another-session'));
    }

    public function testRejectsExpiredAndTamperedTokens(): void
    {
        $signer = new OAuthAccountTokenSigner(self::SECRET);
        $expired = $signer->issue('oauth:github', '42', null, 'User', self::SESSION, -1);
        $valid = $signer->issue('oauth:github', '42', null, 'User', self::SESSION, 600);

        self::assertNull($signer->verify($expired, self::SESSION));
        self::assertNull($signer->verify($valid . 'x', self::SESSION));
        self::assertNull($signer->verify('bad-token', self::SESSION));
    }

    public function testLinkAndAccountTokensCannotBeInterchanged(): void
    {
        $accountSigner = new OAuthAccountTokenSigner(self::SECRET);
        $linkSigner = new OAuthLinkTokenSigner(self::SECRET);
        $account = $accountSigner->issue('oauth:github', '42', 'user@example.test', 'User', self::SESSION, 600);
        $link = $linkSigner->issue('oauth:github', '42', 'user@example.test', 600);

        self::assertNull($accountSigner->verify($link, self::SESSION));
        self::assertNull($linkSigner->verify($account));
    }
}
