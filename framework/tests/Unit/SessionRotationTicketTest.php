<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\SessionRotationTicket;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the one-time ticket that carries a token rotation (HIL-582).
 *
 * The ticket is what the master trades for the rotated session on the next 101, so
 * its form is the whole of its security surface: anything the master accepts, an
 * attacker may try to guess or forge. Pinned here are the two halves of that - what
 * mint() emits, and what the predicate refuses. Uppercase hex is refused for the same
 * reason it is refused for a session token: no minted ticket can look like that, so a
 * value that does was not minted here.
 */
final class SessionRotationTicketTest extends TestCase
{
    /** @var int Length of a minted ticket in hex characters */
    private const int HEX_LENGTH = 32;

    public function testMintEmitsThirtyTwoLowercaseHexCharacters(): void
    {
        $ticket = SessionRotationTicket::mint();

        $this->assertSame(self::HEX_LENGTH, strlen($ticket));
        $this->assertSame(1, preg_match('/\A[0-9a-f]+\z/', $ticket));
    }

    public function testMintEmitsAValidTicket(): void
    {
        $this->assertTrue(SessionRotationTicket::isValid(SessionRotationTicket::mint()));
    }

    public function testTwoMintsDiffer(): void
    {
        $this->assertNotSame(SessionRotationTicket::mint(), SessionRotationTicket::mint());
    }

    /**
     * @param string $ticket Value the predicate must refuse
     */
    #[DataProvider('malformedTickets')]
    public function testMalformedValuesAreRefused(string $ticket): void
    {
        $this->assertFalse(SessionRotationTicket::isValid($ticket));
    }

    /**
     * @param string $ticket Value ensureValid() must throw on
     */
    #[DataProvider('malformedTickets')]
    public function testEnsureValidThrowsOnMalformedValues(string $ticket): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionRotationTicket::ensureValid($ticket);
    }

    public function testEnsureValidPassesAMintedTicket(): void
    {
        SessionRotationTicket::ensureValid(SessionRotationTicket::mint());

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return array<string, array{string}> Case name to a value that is not a minted ticket
     */
    public static function malformedTickets(): array
    {
        return [
            'empty' => [''],
            'too short' => [str_repeat('a', self::HEX_LENGTH - 1)],
            'too long' => [str_repeat('a', self::HEX_LENGTH + 1)],
            'uppercase hex' => [strtoupper(str_repeat('ab', self::HEX_LENGTH / 2))],
            'non-hex characters' => [str_repeat('g', self::HEX_LENGTH)],
            'a trailing newline' => [str_repeat('a', self::HEX_LENGTH) . "\n"],
            'a leading newline' => ["\n" . str_repeat('a', self::HEX_LENGTH)],
        ];
    }
}
