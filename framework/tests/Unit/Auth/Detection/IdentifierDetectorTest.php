<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Detection;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Hilos;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestClosedHilos;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestHilos;
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
 *
 * One answer is asked of the lookup itself, over a store in which nobody owns the
 * address and the browser holds nothing: what a free address is offered in a project
 * that registers nobody (HIL-2185). That answer depends on the project's declaration
 * and the installation's mail, not on any row, so an empty store is enough to reach it.
 */
final class IdentifierDetectorTest extends TestCase
{
    /** The project method set used wherever the assertion is not about the set itself. */
    private const array ENABLED = [AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK, AuthMethodKey::SMS];

    /** Any token will do: classification refuses, or the account lookup fails, before a hold is read. */
    private const string SESSION_TOKEN = 'identifier-detector-test-session-token';

    /** A relay host that resolves nowhere: nothing here sends, only selects. */
    private const string SMTP_HOST = 'relay.example.invalid';

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
     * @return list<array{string, string, string}> Input, classification and canonical identifier
     */
    public static function readableIdentifiers(): array
    {
        return [
            ['person@example.com', IdentifierDetection::KIND_EMAIL, 'person@example.com'],
            ['  Person@Example.COM  ', IdentifierDetection::KIND_EMAIL, 'person@example.com'],
            ['+15550101234', IdentifierDetection::KIND_PHONE, '+15550101234'],
            ['+1 (555) 010-1234', IdentifierDetection::KIND_PHONE, '+15550101234'],
            ['0015550101234', IdentifierDetection::KIND_PHONE, '+15550101234'],
            ['15550101234', IdentifierDetection::KIND_PHONE, '+15550101234'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$db = null;
    }

    protected function tearDown(): void
    {
        Hilos::$db = null;
        putenv(EnvConstants::MAIL_TRANSPORT->name);
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
        AuthMethodTestHilos::unmount();

        parent::tearDown();
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
     * @param string $kind Expected identifier kind
     * @param string $normalized Expected canonical identifier
     * @throws InvalidFormatException Never: these classify
     * @throws LogicException Always — reaching the lookup with no database is what is asserted
     */
    #[DataProvider('readableIdentifiers')]
    public function testReadableIdentifierReachesTheLookup(string $identifier, string $kind, string $normalized): void
    {
        self::assertSame($kind, IdentifierDetector::kindOf($identifier));
        self::assertSame($normalized, IdentifierDetector::normalize($identifier, $kind));
        $this->expectException(LogicException::class);

        new IdentifierDetector(self::ENABLED)->detect($identifier, self::SESSION_TOKEN);
    }

    /**
     * A free address in a project that registers nobody is offered nothing, and told registration is closed, while mail works.
     */
    public function testAClosedRegistrationOffersNothingOnAFreeAddressMailReaches(): void
    {
        AuthMethodTestClosedHilos::mount(null);
        $this->freeStoreAndWorkingMail();

        $detection = new IdentifierDetector([AuthMethodKey::PASSWORD])->detect('person@example.com', self::SESSION_TOKEN);

        self::assertSame(IdentifierDetection::STATUS_NONE, $detection->status);
        self::assertSame([], $detection->registerable);
        self::assertSame(IdentifierDetection::BLOCK_CLOSED, $detection->registrationBlock);
        self::assertSame('No account for this, and registration is closed.', $detection->message);
    }

    /**
     * The same address in a project that registers is offered the password, as before.
     */
    public function testAnOpenRegistrationOffersThePasswordOnTheSameAddress(): void
    {
        AuthMethodTestHilos::mount(null);
        $this->freeStoreAndWorkingMail();

        $detection = new IdentifierDetector([AuthMethodKey::PASSWORD])->detect('person@example.com', self::SESSION_TOKEN);

        self::assertSame(IdentifierDetection::STATUS_NONE, $detection->status);
        self::assertSame([AuthMethodKey::PASSWORD], $detection->registerable);
        self::assertNull($detection->registrationBlock);
        self::assertNull($detection->message);
    }

    /**
     * Mounts a store where every address is free and selects a mail relay.
     */
    private function freeStoreAndWorkingMail(): void
    {
        Hilos::$db = IdentifierDetectorFreeDbContext::create();
        putenv(EnvConstants::MAIL_TRANSPORT->name . '=smtp');
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=' . self::SMTP_HOST);
    }
}

/**
 * DB context in which every identifier is free: no account owns it, and no browser holds it.
 */
final class IdentifierDetectorFreeDbContext extends HilosDbContext
{
    /**
     * Mounts the two collections the lookup reads, both answering that nothing is there.
     *
     * @return self Mounted context
     */
    public static function create(): self
    {
        $context = new self();
        $context->_objectCollections[HilosDbContext::identities] = IdentifierDetectorNoIdentities::initEmpty();
        $context->_objectCollections[HilosDbContext::registrationReservations] = IdentifierDetectorNoReservations::initEmpty();

        return $context;
    }

    /** No collections beyond the two mounted by create(). */
    public function configure(): void
    {
    }
}

/**
 * Identities in which no account owns any address.
 */
final class IdentifierDetectorNoIdentities extends ObjectIdentities
{
    /**
     * @param string $email Lowercased account email
     * @return ?int Always null: the address is free
     */
    public function findAccountIdByEmail(string $email): ?int
    {
        return null;
    }
}

/**
 * Registration holds in which no browser holds anything.
 */
final class IdentifierDetectorNoReservations extends ObjectRegistrationReservations
{
    /**
     * @param string $sessionToken Browser asking about its own hold
     * @return ?ObjectRegistrationReservation Always null: the browser holds nothing
     */
    public function findActiveForSession(string $sessionToken): ?ObjectRegistrationReservation
    {
        return null;
    }
}
