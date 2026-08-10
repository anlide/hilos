<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\SessionToken;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the single owner of the session token form (HIL-556).
 *
 * The form is defined as "exactly what mint() emits", so the two halves are
 * tested against each other: what the master issues must pass the check every
 * consumer runs, and everything else must fail it.
 */
final class SessionTokenTest extends TestCase
{
    public function testMintEmitsThirtyTwoLowercaseHexCharacters(): void
    {
        $token = SessionToken::mint();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    public function testMintedTokenPassesItsOwnCheck(): void
    {
        $this->assertTrue(SessionToken::isValid(SessionToken::mint()));
    }

    public function testEachMintDiffers(): void
    {
        $this->assertNotSame(SessionToken::mint(), SessionToken::mint());
    }

    /**
     * @param string $token Value that must not pass for the stated reason
     * @param string $reason What is wrong with it
     */
    #[DataProvider('rejectedTokens')]
    public function testRejectsAnythingThatIsNotAMintedToken(string $token, string $reason): void
    {
        $this->assertFalse(SessionToken::isValid($token), $reason);
    }

    public function testEnsureValidPassesAMintedToken(): void
    {
        $this->expectNotToPerformAssertions();

        SessionToken::ensureValid(SessionToken::mint());
    }

    public function testEnsureValidThrowsOnUppercaseHex(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Invalid session token format. Expected 32 lowercase hex characters.');

        SessionToken::ensureValid(strtoupper(SessionToken::mint()));
    }

    public function testEnsureValidThrowsOnAnEmptyToken(): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionToken::ensureValid('');
    }

    /**
     * @return array<string, array{string, string}> Rejected value and the reason it is rejected
     */
    public static function rejectedTokens(): array
    {
        return [
            'empty' => ['', 'an empty string is not a token'],
            'one character short' => [str_repeat('a', 31), '31 characters is not the minted length'],
            'one character long' => [str_repeat('a', 33), '33 characters is not the minted length'],
            'not hex' => [str_repeat('a', 31) . 'z', 'z is outside the hex alphabet'],
            'uppercase hex' => [str_repeat('A', 32), 'no minted token carries uppercase hex'],
            'leading whitespace' => [' ' . str_repeat('a', 31), 'the form is anchored at both ends'],
            'trailing newline' => [str_repeat('a', 32) . "\n", 'the form is anchored at both ends'],
        ];
    }
}
