<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Flow;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\SecondFactor\DTO\SecondFactorStepData;
use Hilos\Auth\SecondFactor\SecondFactorMessages;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the wire form of an auth submit's answer (HIL-413 contract).
 *
 * The reply is read by a browser that was given no schema for it, so what it does
 * and does not carry is the contract: an absent slot means "nothing to say about
 * this", and the surface leaves whatever it is showing alone. Locking the omission
 * matters as much as locking the value - a key that appeared as null would read as
 * "there is no countdown" and wipe a live one.
 */
final class AuthFlowOutcomeTest extends TestCase
{
    private const string STEP = 'code';
    private const string INTENT = 'register';
    private const int RESEND_AT = 1_760_000_060_000;
    private const int EXPIRES_AT = 1_760_000_900_000;

    public function testAMoveCarriesBothMomentsToTheSurface(): void
    {
        $wire = AuthFlowOutcome::moveTo(self::STEP, self::INTENT, self::RESEND_AT, self::EXPIRES_AT)->toArray();

        self::assertTrue($wire['ok']);
        self::assertSame(['step' => self::STEP, 'intent' => self::INTENT], $wire['next']);
        self::assertSame(self::RESEND_AT, $wire['resendAt']);
        self::assertSame(self::EXPIRES_AT, $wire['expiresAt']);
    }

    public function testASendThatMovesNowhereStillNamesWhatItSent(): void
    {
        $wire = AuthFlowOutcome::sent(self::RESEND_AT, self::EXPIRES_AT)->toArray();

        self::assertTrue($wire['ok']);
        self::assertArrayNotHasKey('next', $wire);
        self::assertSame(self::EXPIRES_AT, $wire['expiresAt']);
    }

    public function testAnOutcomeWithNothingWaitingOmitsTheMomentsEntirely(): void
    {
        $wire = AuthFlowOutcome::moveTo(self::STEP, self::INTENT)->toArray();

        self::assertArrayNotHasKey('resendAt', $wire);
        self::assertArrayNotHasKey('expiresAt', $wire);
    }

    public function testARefusalCarriesNoMoments(): void
    {
        $wire = AuthFlowOutcome::refuse(AuthFlowOutcome::CODE_SEND_CAP_REACHED, 'Too many')->toArray();

        self::assertFalse($wire['ok']);
        self::assertSame(AuthFlowOutcome::CODE_SEND_CAP_REACHED, $wire['code']);
        self::assertArrayNotHasKey('expiresAt', $wire);
    }

    /**
     * @throws InvalidFormatException When the fixture is not a valid outcome
     */
    public function testTheWireFormRestoresEveryMoment(): void
    {
        $restored = AuthFlowOutcome::fromArray(
            AuthFlowOutcome::moveTo(self::STEP, self::INTENT, self::RESEND_AT, self::EXPIRES_AT)->toArray(),
        );

        self::assertSame(self::RESEND_AT, $restored->resendAt);
        self::assertSame(self::EXPIRES_AT, $restored->expiresAt);
    }

    /**
     * @param string $code Existing refusal code
     * @param string $message Former client fallback, written literally
     * @throws InvalidFormatException When the fixture is not a valid outcome
     */
    #[DataProvider('bareCodeRefusals')]
    public function testABareCodeRefusalCarriesItsFormerFallbackThroughReplay(string $code, string $message): void
    {
        $outcome = AuthFlowOutcome::rejectTo($code, self::STEP, self::INTENT);
        $expected = [
            'ok' => false,
            'next' => ['step' => self::STEP, 'intent' => self::INTENT],
            'code' => $code,
            'message' => $message,
        ];

        self::assertSame($message, $outcome->message);
        self::assertSame($expected, $outcome->toArray());
        $restored = AuthFlowOutcome::fromArray($outcome->toArray());
        self::assertSame($message, $restored->message);
        self::assertSame($expected, $restored->toArray());
    }

    /**
     * @return array<string, array{string, string}> Codes and their former fallback sentences
     */
    public static function bareCodeRefusals(): array
    {
        return [
            'taken address' => ['identifier_taken', 'That address already has an account — sign in instead.'],
            'expired registration' => ['reservation_expired', 'That registration expired. Ask for a new code.'],
            'expired reset' => ['reset_code_expired', 'That reset code has expired. Ask for a new one.'],
            'completed recovery' => [
                'password_already_changed',
                'The password was already changed on another device. Sign in with the new one.',
            ],
            'invalid link' => ['magic_link_invalid', 'That sign-in link is no longer valid. Ask for a new one.'],
            'send cap' => ['send_cap_reached', 'Too many codes have gone out. Please try again later.'],
            'throttle' => ['rate_limited', 'Too many attempts. Please wait a moment and try again.'],
            'challenge' => ['challenge_required', 'Please confirm you are not a robot and try again.'],
            'expired second factor' => ['second_factor_expired', 'Your sign-in step expired. Sign in again.'],
            'second factor attempts' => ['second_factor_attempts', 'Too many wrong codes. Sign in again.'],
            'blocked account' => ['account_blocked', 'This account has been blocked by the project administration.'],
            'expired OAuth' => ['oauth_sign_in_expired', 'Your sign-in has expired. Continue with your provider again.'],
        ];
    }

    /**
     * @param string $code Refusal code with a different fallback
     * @param string $message Explicit command sentence
     * @param string $expected Existing sentence, written literally
     * @throws InvalidFormatException When the fixture is not a valid outcome
     */
    #[DataProvider('explicitMessages')]
    public function testExplicitMessagesSurviveBothRefusalFactoriesAndReplay(string $code, string $message, string $expected): void
    {
        foreach ([
            AuthFlowOutcome::rejectTo($code, self::STEP, self::INTENT, $message),
            AuthFlowOutcome::refuse($code, $message),
        ] as $outcome) {
            self::assertSame($expected, $outcome->message);
            self::assertSame($expected, $outcome->toArray()['message']);
            $restored = AuthFlowOutcome::fromArray($outcome->toArray());
            self::assertSame($expected, $restored->message);
            self::assertSame($outcome->toArray(), $restored->toArray());
        }
    }

    /**
     * @return array<string, array{string, string, string}> Explicit messages and literal expected text
     */
    public static function explicitMessages(): array
    {
        return [
            'email' => ['identifier_taken', AuthMessages::IDENTIFIER_TAKEN, 'This email already has an account'],
            'phone' => ['identifier_taken', AuthMessages::PHONE_TAKEN, 'This phone number already has an account'],
            'registration' => ['reservation_expired', AuthMessages::RESERVATION_EXPIRED, 'That registration expired, please start again'],
            'reset' => ['reset_code_expired', AuthMessages::RESET_CODE_EXPIRED, 'That reset code is no longer valid, please start again'],
            'link' => ['magic_link_invalid', AuthMessages::MAGIC_LINK_INVALID, 'This sign-in link is invalid or expired'],
            'recovery' => ['password_already_changed', AuthMessages::PASSWORD_ALREADY_CHANGED, 'The password was already changed, please sign in'],
            'second factor' => ['second_factor_expired', SecondFactorMessages::STEP_EXPIRED, 'Your sign-in step expired, start again'],
            'custom' => ['account_blocked', 'Contact the project administrator.', 'Contact the project administrator.'],
            'empty supplied string' => ['identifier_taken', '', ''],
        ];
    }

    /**
     * Silent consent and an unknown future code must not gain a generic refusal.
     *
     * @throws InvalidFormatException When the fixture is not a valid outcome
     */
    public function testCodesWithoutWordsKeepTheOmissionContract(): void
    {
        foreach ([AuthFlowOutcome::CODE_CONSENT_REQUIRED, 'future_code'] as $code) {
            $outcome = AuthFlowOutcome::rejectTo($code, AuthFlowStep::CONSENT, AuthFlowIntent::REGISTER);
            $expected = [
                'ok' => false,
                'next' => ['step' => 'consent', 'intent' => 'register'],
                'code' => $code,
            ];

            self::assertNull($outcome->message);
            self::assertSame($expected, $outcome->toArray());
            $restored = AuthFlowOutcome::fromArray($expected);
            self::assertNull($restored->message);
            self::assertSame($expected, $restored->toArray());
        }
    }

    /**
     * @throws InvalidFormatException When the fixture is not a valid outcome
     */
    public function testAllSuccessfulFactoriesKeepMessagesAbsentThroughReplay(): void
    {
        $secondFactor = new SecondFactorStepData(null, null);
        foreach ([
            AuthFlowOutcome::moveTo(self::STEP, self::INTENT),
            AuthFlowOutcome::moveTo(self::STEP, self::INTENT, self::RESEND_AT, self::EXPIRES_AT),
            AuthFlowOutcome::sent(self::RESEND_AT, self::EXPIRES_AT),
            AuthFlowOutcome::moveToSecondFactor(AuthFlowStep::SECOND_FACTOR, $secondFactor, self::EXPIRES_AT),
            AuthFlowOutcome::secondFactorData($secondFactor),
        ] as $outcome) {
            self::assertTrue($outcome->ok);
            self::assertNull($outcome->message);
            self::assertArrayNotHasKey('message', $outcome->toArray());
            self::assertArrayNotHasKey('code', $outcome->toArray());
            $restored = AuthFlowOutcome::fromArray($outcome->toArray());
            self::assertNull($restored->message);
            self::assertSame($outcome->toArray(), $restored->toArray());
        }
    }

    /**
     * @throws InvalidFormatException When the fixture is not a valid outcome
     */
    public function testReplayDoesNotInventWordsForAnExistingPayloadWithoutMessage(): void
    {
        $wire = [
            'ok' => false,
            'next' => ['step' => 'identifier', 'intent' => 'login'],
            'code' => 'identifier_taken',
        ];

        $restored = AuthFlowOutcome::fromArray($wire);
        self::assertNull($restored->message);
        self::assertSame($wire, $restored->toArray());
    }

    /**
     * @param mixed $message Invalid optional message value
     * @throws InvalidFormatException When the message is not a string
     */
    #[DataProvider('invalidMessages')]
    public function testReplayRejectsANonStringMessage(mixed $message): void
    {
        $this->expectException(InvalidFormatException::class);

        AuthFlowOutcome::fromArray(['ok' => false, 'code' => 'identifier_taken', 'message' => $message]);
    }

    /**
     * @return array<string, array{mixed}> Non-string message payloads
     */
    public static function invalidMessages(): array
    {
        return ['number' => [123], 'boolean' => [false], 'list' => [['message']], 'object' => [(object)['text' => 'message']]];
    }
}
