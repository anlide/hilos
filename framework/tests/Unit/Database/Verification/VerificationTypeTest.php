<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Verification;

use Hilos\Database\Verification\VerificationType;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the verification type value set (HIL-365).
 *
 * Locks the constants to the exact strings of the SQL ENUM on
 * hilos_user_verification; a drift here silently breaks issue/verify lookups.
 */
final class VerificationTypeTest extends TestCase
{
    public function testConstantsMatchSqlEnumValues(): void
    {
        self::assertSame('register_confirm', VerificationType::REGISTER_CONFIRM);
        self::assertSame('password_reset', VerificationType::PASSWORD_RESET);
        self::assertSame('email_change', VerificationType::EMAIL_CHANGE);
        self::assertSame('sms_login', VerificationType::SMS_LOGIN);
        self::assertSame('magic_link', VerificationType::MAGIC_LINK);
        self::assertSame('sms_add', VerificationType::SMS_ADD);
        self::assertSame('email_add', VerificationType::EMAIL_ADD);
    }

    public function testValuesAreDistinct(): void
    {
        $values = [
            VerificationType::REGISTER_CONFIRM,
            VerificationType::PASSWORD_RESET,
            VerificationType::EMAIL_CHANGE,
            VerificationType::SMS_LOGIN,
            VerificationType::MAGIC_LINK,
            VerificationType::SMS_ADD,
            VerificationType::EMAIL_ADD,
        ];

        self::assertSame($values, array_values(array_unique($values)));
    }

    public function testValuesReturnsTheFullSetInDeclarationOrder(): void
    {
        self::assertSame(
            [
                VerificationType::REGISTER_CONFIRM,
                VerificationType::PASSWORD_RESET,
                VerificationType::EMAIL_CHANGE,
                VerificationType::SMS_LOGIN,
                VerificationType::MAGIC_LINK,
                VerificationType::SMS_ADD,
                VerificationType::EMAIL_ADD,
            ],
            VerificationType::values(),
        );
    }

    public function testIsValidAcceptsEveryKnownType(): void
    {
        foreach (VerificationType::values() as $type) {
            self::assertTrue(VerificationType::isValid($type));
        }
    }

    public function testIsValidRejectsUnknownEmptyOrMiscasedType(): void
    {
        self::assertFalse(VerificationType::isValid(''));
        self::assertFalse(VerificationType::isValid('unknown'));
        self::assertFalse(VerificationType::isValid('Password_Reset'));
    }
}
