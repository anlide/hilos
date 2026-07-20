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
    }

    public function testValuesAreDistinct(): void
    {
        $values = [
            VerificationType::REGISTER_CONFIRM,
            VerificationType::PASSWORD_RESET,
            VerificationType::EMAIL_CHANGE,
            VerificationType::SMS_LOGIN,
            VerificationType::MAGIC_LINK,
        ];

        self::assertSame($values, array_values(array_unique($values)));
    }
}
