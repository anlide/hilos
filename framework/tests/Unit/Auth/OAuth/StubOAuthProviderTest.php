<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\Exception\OAuthProviderException;
use Hilos\Auth\OAuth\StubOAuthProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the offline dev/e2e OAuth provider (HIL-281 / HIL-573).
 *
 * Locks what a test may ask the stub for by choosing a code: the deterministic
 * account it resolves, and the two withholding markers that reproduce a provider
 * which hands over no address, no name, or neither.
 */
final class StubOAuthProviderTest extends TestCase
{
    public function testResolveDerivesTheAccountFromThePlainCode(): void
    {
        $info = (new StubOAuthProvider())->resolve('octo');

        self::assertSame('stub:octo', $info->subject);
        self::assertSame('octo@stub.local', $info->email);
        self::assertSame('stub-octo', $info->name);
    }

    public function testResolveReportsALowercasedAddressForAMixedCaseCode(): void
    {
        $info = (new StubOAuthProvider())->resolve('OctoCat');

        self::assertSame('stub:OctoCat', $info->subject);
        self::assertSame('octocat@stub.local', $info->email);
    }

    public function testResolveWithholdsTheEmailOnTheNoEmailMarker(): void
    {
        $info = (new StubOAuthProvider())->resolve('octo-' . StubOAuthProvider::MARKER_NO_EMAIL);

        self::assertNull($info->email);
        self::assertNotNull($info->name);
    }

    public function testResolveWithholdsTheNameOnTheNoNameMarker(): void
    {
        $info = (new StubOAuthProvider())->resolve('octo-' . StubOAuthProvider::MARKER_NO_NAME);

        self::assertNull($info->name);
        self::assertNotNull($info->email);
    }

    public function testResolveWithholdsBothWhenTheCodeCarriesBothMarkers(): void
    {
        $code = StubOAuthProvider::MARKER_NO_EMAIL . '-' . StubOAuthProvider::MARKER_NO_NAME;

        $info = (new StubOAuthProvider())->resolve($code);

        self::assertSame('stub:' . $code, $info->subject);
        self::assertNull($info->email);
        self::assertNull($info->name);
    }

    public function testResolveRejectsAnEmptyCode(): void
    {
        $this->expectException(OAuthProviderException::class);
        (new StubOAuthProvider())->resolve('');
    }
}
