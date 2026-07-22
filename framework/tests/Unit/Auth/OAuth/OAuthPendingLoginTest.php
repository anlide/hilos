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
        $this->assertSame(OAuthPendingLogin::MODE_LOGIN, $op->mode);
        $this->assertSame(0, $op->linkUserId);
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
                OAuthPendingLogin::mode => OAuthPendingLogin::MODE_LOGIN,
                OAuthPendingLogin::linkUserId => 0,
            ],
            $op->toArray(),
        );
    }

    public function testCreateWithLinkModeCarriesModeAndTargetUser(): void
    {
        $op = OAuthPendingLogin::create(
            'accept-9',
            'session-ghi',
            'oauth:github',
            'code-link',
            5000.0,
            OAuthPendingLogin::MODE_LINK,
            77,
        );

        $this->assertSame(OAuthPendingLogin::MODE_LINK, $op->mode);
        $this->assertSame(77, $op->linkUserId);
        $this->assertSame(OAuthPendingLogin::MODE_LINK, $op->toArray()[OAuthPendingLogin::mode]);
        $this->assertSame(77, $op->toArray()[OAuthPendingLogin::linkUserId]);
        $this->assertSame($op->toArray(), OAuthPendingLogin::fromRow($op->toArray())->toArray());
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
        $this->assertSame(OAuthPendingLogin::MODE_LOGIN, $op->mode);
        $this->assertSame(0, $op->linkUserId);
    }

    public function testRtCollectionKeyIsStable(): void
    {
        $this->assertSame('hilosOAuthPendingLogins', OAuthPendingLogin::getRtCollectionKey());
        $this->assertSame(OAuthPendingLogin::RT_COLLECTION, OAuthPendingLogin::getRtCollectionKey());
    }
}
