<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Detection;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for how the live lookup classifies what was typed (HIL-414).
 *
 * Classification is the half of detection that runs before anything is read: an
 * identifier is an address or a number, and an input that is neither is a
 * validation error of the action rather than a third `unknown` answer the surface
 * would have to render. That is exactly the half a unit test can reach — every
 * classified identifier then goes to the identity layer, so what the lookup
 * ANSWERS is covered where a database exists (demo/chat integration).
 *
 * Which is also how a well-formed identifier is asserted here: with no database
 * configured, getting past classification surfaces as the collection being
 * unavailable. The distinction under test is "rejected as unreadable" versus
 * "read and taken to the lookup", and that is what the two exceptions say.
 */
final class IdentifierDetectorTest extends TestCase
{
    /** The project method set used wherever the assertion is not about the set itself. */
    private const array ENABLED = [AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK, AuthMethodKey::SMS];

    /** Any token will do: classification refuses, or the account lookup fails, before a hold is read. */
    private const string SESSION_TOKEN = 'identifier-detector-test-session-token';

    /**
     * @return list<array{string}> Inputs that are neither an address nor a number
     */
    public static function unreadableIdentifiers(): array
    {
        return [
            [''],
            ['   '],
            ['pers'],
            ['person@'],
            ['@example.com'],
            ['person example.com'],
            ['+1234567'],
            ['+1234567890123456'],
            ['+1 555 01O 1234'],
        ];
    }

    /**
     * @return list<array{string}> Inputs a surface may legitimately look up
     */
    public static function readableIdentifiers(): array
    {
        return [
            ['person@example.com'],
            ['  Person@Example.COM  '],
            ['+15550101234'],
            ['+1 (555) 010-1234'],
            ['0015550101234'],
            ['15550101234'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$db = null;
    }

    /**
     * An input that reads as neither an address nor a number is refused, not answered.
     *
     * @param string $identifier Input that should not classify
     * @throws InvalidFormatException Always — that is what is asserted
     * @throws LogicException Never: classification refuses before anything is read
     */
    #[DataProvider('unreadableIdentifiers')]
    public function testUnreadableIdentifierIsRefused(string $identifier): void
    {
        $this->expectException(InvalidFormatException::class);

        new IdentifierDetector(self::ENABLED)->detect($identifier, self::SESSION_TOKEN);
    }

    /**
     * A well-formed address or number classifies and is carried on to the account lookup.
     *
     * @param string $identifier Input that should classify
     * @throws InvalidFormatException Never: these classify
     * @throws LogicException Always — reaching the lookup with no database is what is asserted
     */
    #[DataProvider('readableIdentifiers')]
    public function testReadableIdentifierReachesTheLookup(string $identifier): void
    {
        $this->expectException(LogicException::class);

        new IdentifierDetector(self::ENABLED)->detect($identifier, self::SESSION_TOKEN);
    }
}
