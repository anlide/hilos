<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\Command\AuthMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthMessagesTest extends TestCase
{
    /**
     * @param string $code Existing convergence or resume reason
     * @param string $expected Former notice sentence, written literally
     */
    #[DataProvider('noticeMessages')]
    public function testNoticesKeepTheirOwnWording(string $code, string $expected): void
    {
        self::assertSame($expected, AuthMessages::forNoticeCode($code));
    }

    /**
     * @return array<string, array{string, string}> Notice reasons and exact expected sentences
     */
    public static function noticeMessages(): array
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
        ];
    }

    /** Silent and unknown reasons carry no notice; OAuth's fallback is only a refusal. */
    public function testNoNoticeIsInventedForOtherCodes(): void
    {
        self::assertNull(AuthMessages::forNoticeCode('consent_required'));
        self::assertNull(AuthMessages::forNoticeCode('oauth_sign_in_expired'));
        self::assertNull(AuthMessages::forNoticeCode('future_code'));
        self::assertNull(AuthMessages::forFallbackCode('consent_required'));
        self::assertNull(AuthMessages::forFallbackCode('future_code'));
    }
}
