<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth;

use Hilos\Auth\Exception\PasswordTooCommonException;
use Hilos\Auth\Exception\PasswordUnchangedException;
use Hilos\Auth\PasswordPolicy;
use Hilos\Core\Exception\ValueTooShortException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    /**
     * @param string $password A known common password, possibly in another case
     */
    #[DataProvider('commonPasswords')]
    public function testCommonPasswordsAreRefusedIgnoringCase(string $password): void
    {
        $this->expectException(PasswordTooCommonException::class);
        $this->expectExceptionMessage('That password is too common and easy to guess, choose a different one');

        PasswordPolicy::assertValid($password, false);
    }

    /** @return iterable<string, array{string}> Known members of the pinned list */
    public static function commonPasswords(): iterable
    {
        yield 'digits' => ['12345678'];
        yield 'word with digit' => ['password1'];
        yield 'keyboard sequence' => ['qwerty123'];
        yield 'phrase' => ['iloveyou'];
        yield 'case folded' => ['PASSWORD1'];
    }

    public function testLengthIsJudgedFirst(): void
    {
        $this->expectException(ValueTooShortException::class);

        PasswordPolicy::assertValid(str_repeat('a', PasswordPolicy::MIN_LENGTH - 1), true);
    }

    public function testTheListTakesPrecedenceOverAnUnchangedPassword(): void
    {
        $this->expectException(PasswordTooCommonException::class);

        PasswordPolicy::assertValid('12345678', true);
    }

    public function testAnUnlistedCurrentPasswordIsStillRefused(): void
    {
        $this->expectException(PasswordUnchangedException::class);

        PasswordPolicy::assertValid('correct horse battery staple', true);
    }

    public function testAnUnlistedNewPasswordPasses(): void
    {
        $this->expectNotToPerformAssertions();

        PasswordPolicy::assertValid('correct horse battery staple', false);
    }
}
