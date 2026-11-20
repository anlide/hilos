<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Registration;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\AbstractLibraryCommands;
use Hilos\Auth\Library\Command\ActingSession;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestClosedHilos;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestHilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A project that registers nobody refuses the registration that rides on a sign-in (HIL-2185).
 *
 * The magic link and the phone code sign a member in and register a free identifier with one
 * letter, so their actions pass the lock in front of the library. Their registration is refused
 * where the free identifier would be held, and once more where the account would be minted.
 * A provider's first sign-in holds nothing and passes no action the lock refuses; it is refused
 * where its account is minted without a hold.
 *
 * Every refusal stands before anything is read: the cases run with no database behind the
 * facade, so a refusal that came later would meet the missing database first and fail with
 * something else.
 */
final class ClosedRegistrationTest extends TestCase
{
    private const string SESSION_TOKEN = 'closed-registration-session';

    private const string ACCEPT_KEY = 'closed-registration-ak';

    private const string FREE_ADDRESS = 'stranger@example.test';

    private const string PROVIDER_IDENTIFIER = 'oauth:github:stranger';

    protected function tearDown(): void
    {
        AuthMethodTestHilos::unmount();

        parent::tearDown();
    }

    /**
     * Every road that holds a free identifier, each with the identifier it holds.
     *
     * @return array<string, array{string, string}>
     */
    public static function freeIdentifiers(): array
    {
        return [
            'magic link' => [IdentityType::MAGIC_LINK, self::FREE_ADDRESS],
            'phone code' => [IdentityType::SMS, '+15550100'],
            'password' => [IdentityType::PASSWORD, self::FREE_ADDRESS],
        ];
    }

    /**
     * No road holds a free address or number for a registration the project does not open.
     */
    #[DataProvider('freeIdentifiers')]
    public function testAClosedProjectHoldsNoFreeIdentifier(string $type, string $identifier): void
    {
        AuthMethodTestClosedHilos::mount(null);

        $this->expectExceptionObject(new ValidationException(AuthMessages::REGISTRATION_CLOSED));

        new RegistrationReservationService()->hold($type, self::SESSION_TOKEN, $identifier, []);
    }

    /**
     * A proof that reaches the landing all the same mints no account.
     *
     * The library behind the commands makes no users, so a landing that got as far as the
     * mint would fail the case with its own error instead of the refusal.
     */
    public function testAClosedProjectLandsNoAccount(): void
    {
        AuthMethodTestClosedHilos::mount(null);
        $commands = new ClosedRegistrationTestCommands(new ClosedRegistrationTestUsersAgent());

        $this->expectExceptionObject(new ValidationException(AuthMessages::REGISTRATION_CLOSED));

        $commands->land(new ActingSession(self::ACCEPT_KEY, self::SESSION_TOKEN, null), self::FREE_ADDRESS);
    }

    /**
     * A provider's first sign-in, which lands without a hold, mints no account either.
     *
     * The ending without a hold is the only lock on a provider the project declared without
     * wiring it: the start refuses a wired one alone, and the hold is never asked.
     */
    public function testAClosedProjectMintsNoAccountWithoutAHold(): void
    {
        AuthMethodTestClosedHilos::mount(null);
        $commands = new ClosedRegistrationTestCommands(new ClosedRegistrationTestUsersAgent());

        $this->expectExceptionObject(new ValidationException(AuthMessages::REGISTRATION_CLOSED));

        $commands->landWithoutHold(new ActingSession(self::ACCEPT_KEY, self::SESSION_TOKEN, null), self::PROVIDER_IDENTIFIER);
    }
}

/**
 * Users library that makes no users: reaching its mint is what the landing case must not do.
 */
final class ClosedRegistrationTestUsersAgent extends AbstractUsersLibraryAgent
{
    /**
     * @param string $displayName Proposed account name
     * @param ?string $themePick Guest theme choice, or null when not chosen
     * @return int Never returns
     * @throws LogicException Always: the fixture library makes no users
     */
    public function createUser(string $displayName, ?string $themePick): int
    {
        throw new LogicException('The fixture library makes no users');
    }
}

/**
 * Commands group that exposes the two endings that mint an account; it holds no commands of its own.
 */
final class ClosedRegistrationTestCommands extends AbstractLibraryCommands
{
    /**
     * @param ActingSession $acting Browser the proof arrived on
     * @param string $identifier Proven identifier
     * @return ?AuthFlowOutcome What the landing answered
     */
    public function land(ActingSession $acting, string $identifier): ?AuthFlowOutcome
    {
        return $this->landRegistration($acting, $identifier, 'Stranger', null);
    }

    /**
     * @param ActingSession $acting Browser that asked for the account
     * @param string $identifier Sign-in method identifier
     */
    public function landWithoutHold(ActingSession $acting, string $identifier): void
    {
        $this->landAccountWithoutHold(
            $acting,
            $identifier,
            'Stranger',
            null,
            static function (int $userId): void {
            },
            [],
        );
    }
}
