<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Code;

use Hilos\Auth\Code\DTO\AuthCodeMintSignalData;
use Hilos\Auth\Code\DTO\AuthCodeMintedSignalData;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationHoldExpiredSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationHoldReleaseSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Both directions survive transport; a delivery order never leaves its outcome ambiguous. */
final class AuthCodeMintSignalDataTest extends TestCase
{
    /** @throws HilosException When a valid transport payload is refused */
    public function testMintPreservesTheEntireRequestAndProbeHandle(): void
    {
        foreach ([null, 'probe-handle'] as $probeToken) {
            $mint = new AuthCodeMintSignalData(self::request(), $probeToken);
            self::assertEquals($mint, AuthCodeMintSignalData::fromArray($mint->toArray()));
        }
    }

    /** @throws HilosException When a valid transport payload is refused */
    public function testMintedPreservesTheCodeAndAllDeliveryFacts(): void
    {
        $minted = new AuthCodeMintedSignalData(self::request(), 'probe', '123456', null, 123000, 456000, true);
        self::assertEquals($minted, AuthCodeMintedSignalData::fromArray($minted->toArray()));
    }

    /** @throws HilosException When a permitted refusal is rejected */
    public function testEveryPermittedRefusalRoundTripsWithoutACode(): void
    {
        foreach ([
            HilosCodeSendAttempt::REASON_RATE_LIMITED,
            HilosCodeSendAttempt::REASON_CAP_REACHED,
            HilosCodeSendAttempt::REASON_SEND_FAILED,
            HilosCodeSendAttempt::REASON_TERMS_UNPUBLISHED,
            HilosCodeSendAttempt::REASON_CONSENT_REQUIRED,
            HilosCodeSendAttempt::REASON_CONSENT_REVISED,
        ] as $reason) {
            $minted = new AuthCodeMintedSignalData(self::request(), null, null, $reason, null, null, false);
            self::assertEquals($minted, AuthCodeMintedSignalData::fromArray($minted->toArray()));
        }
    }

    /**
     * @param ?string $code Code under test
     * @param ?string $reason Refusal under test
     * @param bool $fromArray Whether to enter through the transport reader
     * @throws HilosException The expected refusal
     */
    #[DataProvider('ambiguousOutcomes')]
    public function testAnOrderMustNameExactlyOnePermittedOutcome(?string $code, ?string $reason, bool $fromArray): void
    {
        $this->expectException(InvalidFormatException::class);
        if ($fromArray) {
            AuthCodeMintedSignalData::fromArray([
                AuthCodeMintedSignalData::request => self::request()->toArray(),
                AuthCodeMintedSignalData::code => $code,
                AuthCodeMintedSignalData::reason => $reason,
                AuthCodeMintedSignalData::registration => false,
            ]);

            return;
        }

        new AuthCodeMintedSignalData(self::request(), null, $code, $reason, null, null, false);
    }

    /** @return iterable<string, array{?string, ?string, bool}> Invalid outcomes at both entrances */
    public static function ambiguousOutcomes(): iterable
    {
        foreach ([false, true] as $fromArray) {
            $entry = $fromArray ? 'array' : 'constructor';
            yield "$entry neither" => [null, null, $fromArray];
            yield "$entry both" => ['123456', HilosCodeSendAttempt::REASON_RATE_LIMITED, $fromArray];
            yield "$entry unknown" => [null, 'unknown-refusal', $fromArray];
        }
    }

    /** @throws HilosException The expected missing-field refusal */
    public function testMintRequiresTheNestedRequest(): void
    {
        $this->expectException(InvalidFormatException::class);
        AuthCodeMintSignalData::fromArray([]);
    }

    /** @throws HilosException The expected missing-field refusal */
    public function testMintedRequiresAnExplicitRegistrationDecision(): void
    {
        $this->expectException(InvalidFormatException::class);
        AuthCodeMintedSignalData::fromArray([
            AuthCodeMintedSignalData::request => self::request()->toArray(),
            AuthCodeMintedSignalData::code => '123456',
        ]);
    }

    /** @throws HilosException When a hold payload is refused */
    public function testHoldFramesPreserveTheirSessionScope(): void
    {
        $expired = new AuthRegistrationHoldExpiredSignalData('session', '+12025550111');
        self::assertEquals($expired, AuthRegistrationHoldExpiredSignalData::fromArray($expired->toArray()));
        $release = new AuthRegistrationHoldReleaseSignalData('session');
        self::assertEquals($release, AuthRegistrationHoldReleaseSignalData::fromArray($release->toArray()));
    }

    /** @throws HilosException The expected missing-field refusal */
    public function testExpiryCannotNameOnlyAnAddress(): void
    {
        $this->expectException(InvalidFormatException::class);
        AuthRegistrationHoldExpiredSignalData::fromArray([AuthRegistrationHoldExpiredSignalData::identifier => '+12025550111']);
    }

    /** @throws HilosException The expected missing-field refusal */
    public function testReleaseCannotOmitTheSession(): void
    {
        $this->expectException(InvalidFormatException::class);
        AuthRegistrationHoldReleaseSignalData::fromArray([]);
    }

    /** @return AuthCodeSendSignalData Full request including consent and progress addressing */
    private static function request(): AuthCodeSendSignalData
    {
        return new AuthCodeSendSignalData(
            'accept', 'session', '+12025550111', 'sms', VerificationType::SMS_LOGIN, 'ticket', ['terms' => '2026-09-17'],
        );
    }
}
