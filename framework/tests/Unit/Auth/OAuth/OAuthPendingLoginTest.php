<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Runtime\State\Item\OAuthPendingLogin;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the in-flight OAuth login RT op (HIL-281).
 *
 * Pins the row contract the callback writer and the async agent share: the accept
 * key is the id, and a row round-trips through the RT-sync array form unchanged.
 */
final class OAuthPendingLoginTest extends TestCase
{
    public function testCreateExposesTheAcceptKeyAsTheId(): void
    {
        $op = OAuthPendingLogin::create('accept-1', 'session-abc', 'oauth:github', 'code-xyz', 1234.5);

        $this->assertSame('accept-1', $op->getId());
        $this->assertSame('accept-1', $op->acceptKey);
    }

    public function testToArrayCarriesEveryField(): void
    {
        $op = OAuthPendingLogin::create('accept-1', 'session-abc', 'oauth:github', 'code-xyz', 1234.5);

        $this->assertSame(
            [
                OAuthPendingLogin::acceptKey => 'accept-1',
                OAuthPendingLogin::sessionToken => 'session-abc',
                OAuthPendingLogin::provider => 'oauth:github',
                OAuthPendingLogin::code => 'code-xyz',
                OAuthPendingLogin::deadlineMs => 1234.5,
            ],
            $op->toArray(),
        );
    }

    public function testFromRowRoundTripsToArray(): void
    {
        $row = OAuthPendingLogin::create('accept-2', 'session-def', 'oauth:stub', 'stub', 9999.0)->toArray();

        $this->assertSame($row, OAuthPendingLogin::fromRow($row)->toArray());
    }

    public function testFromRowDefaultsMissingFields(): void
    {
        $op = OAuthPendingLogin::fromRow([]);

        $this->assertSame('', $op->getId());
        $this->assertSame('', $op->provider);
        $this->assertSame(0.0, $op->deadlineMs);
    }

    public function testRtCollectionKeyIsStable(): void
    {
        $this->assertSame('hilosOAuthPendingLogins', OAuthPendingLogin::getRtCollectionKey());
        $this->assertSame(OAuthPendingLogin::RT_COLLECTION, OAuthPendingLogin::getRtCollectionKey());
    }
}
