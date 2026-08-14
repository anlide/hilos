<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pending-login payload the callback hands the OAuth agent (HIL-281).
 *
 * The payload crosses a process boundary, so every field of the op has to survive the
 * trip or the agent must be told it did not: an exchange assembled out of empty strings
 * and zeros would be run against the provider and answered to nobody.
 */
final class OAuthPendingLoginSignalDataTest extends TestCase
{
    public function testRoundTripsEveryFieldOfTheOp(): void
    {
        $data = new OAuthPendingLoginSignalData(
            'accept-1',
            'session-1',
            'oauth:stub',
            'code-1',
            1_700_000_000_000.0,
            OAuthPendingLogin::MODE_LINK,
            42,
        );

        $restored = OAuthPendingLoginSignalData::fromArray($data->toArray());

        $this->assertSame($data->toArray(), $restored->toArray());
        $this->assertSame(OAuthPendingLogin::MODE_LINK, $restored->mode);
        $this->assertSame(42, $restored->linkUserId);
    }

    public function testDeadlineSurvivesTheWholeNumberJsonWritesItAs(): void
    {
        $payload = new OAuthPendingLoginSignalData('accept-1', 'session-1', 'oauth:stub', 'code-1', 1000.0)->toArray();
        $payload['deadlineMs'] = 1000;

        $this->assertSame(1000.0, OAuthPendingLoginSignalData::fromArray($payload)->deadlineMs);
    }

    public function testRefusesAPayloadThatLostTheAuthorizationCode(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('code');

        OAuthPendingLoginSignalData::fromArray([
            'acceptKey' => 'accept-1',
            'sessionToken' => 'session-1',
            'provider' => 'oauth:stub',
            'deadlineMs' => 1000.0,
            'mode' => OAuthPendingLogin::MODE_LOGIN,
            'linkUserId' => 0,
        ]);
    }

    public function testRefusesAPayloadThatLostTheLinkUserInsteadOfCallingItALogin(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('linkUserId');

        OAuthPendingLoginSignalData::fromArray([
            'acceptKey' => 'accept-1',
            'sessionToken' => 'session-1',
            'provider' => 'oauth:stub',
            'code' => 'code-1',
            'deadlineMs' => 1000.0,
            'mode' => OAuthPendingLogin::MODE_LINK,
        ]);
    }
}
