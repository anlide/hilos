<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OAuth login failure signal (HIL-281).
 *
 * The only OAuth outcome carrying its own signal: it targets the initiating accept
 * key, states a generic reason (provider detail never crosses the wire), and
 * round-trips through its transport array.
 */
final class OAuthResultSignalDataTest extends TestCase
{
    public function testDefaultsToTheGenericFailureReason(): void
    {
        $data = new OAuthResultSignalData('accept-1', 'oauth:github');

        $this->assertSame('accept-1', $data->getAcceptKey());
        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $data->reason);
        $this->assertSame('oauth_login_failed', $data->reason);
    }

    public function testToArrayCarriesTheTargetProviderAndReason(): void
    {
        $data = new OAuthResultSignalData('accept-1', 'oauth:github');

        $this->assertSame(
            [
                'acceptKey' => 'accept-1',
                'provider' => 'oauth:github',
                'reason' => 'oauth_login_failed',
                'email' => '',
                'linkToken' => '',
            ],
            $data->toArray(),
        );
    }

    public function testReauthRequiredCarriesTheCollidingEmailAndLinkToken(): void
    {
        $data = new OAuthResultSignalData(
            'accept-3',
            'oauth:github',
            OAuthResultSignalData::REASON_REAUTH_REQUIRED,
            'user@example.com',
            'link.token',
        );

        $this->assertSame(
            [
                'acceptKey' => 'accept-3',
                'provider' => 'oauth:github',
                'reason' => 'reauth_required',
                'email' => 'user@example.com',
                'linkToken' => 'link.token',
            ],
            $data->toArray(),
        );
        $this->assertSame($data->toArray(), OAuthResultSignalData::fromArray($data->toArray())->toArray());
    }

    public function testFromArrayRoundTripsToArray(): void
    {
        $array = new OAuthResultSignalData('accept-2', 'oauth:stub')->toArray();

        $this->assertSame($array, OAuthResultSignalData::fromArray($array)->toArray());
    }

    public function testFromArrayFallsBackToTheGenericReason(): void
    {
        $data = OAuthResultSignalData::fromArray(['acceptKey' => 'a', 'provider' => 'p']);

        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $data->reason);
    }
}
