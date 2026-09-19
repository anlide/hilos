<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Method\AuthMethodGate;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestHilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-side lock on a switched-off sign-in method (HIL-427).
 *
 * Every row of the lock's map is asked from both sides: open while its method is on,
 * refused with the ordinary action error once it is off.
 */
final class AuthMethodGateTest extends TestCase
{
    /** Every other wired method, so the case switches off exactly the one under test. */
    private const array ALL_BUT_PROVIDERS = [
        AuthMethodKey::PASSWORD,
        AuthMethodKey::PASSKEY,
        AuthMethodKey::MAGIC_LINK,
        AuthMethodKey::SMS,
    ];

    protected function tearDown(): void
    {
        AuthMethodTestHilos::unmount();

        parent::tearDown();
    }

    /**
     * @return list<array{string, string}> Each action gated by one method, and that method
     */
    public static function singleMethodActions(): array
    {
        return [
            [HilosSignalConstants::HILOS_LOGIN, AuthMethodKey::PASSWORD],
            [HilosSignalConstants::HILOS_REGISTER, AuthMethodKey::PASSWORD],
            [HilosSignalConstants::HILOS_COMPLETE_REGISTRATION, AuthMethodKey::PASSWORD],
            [HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET, AuthMethodKey::PASSWORD],
            [HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET, AuthMethodKey::PASSWORD],
            [HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET, AuthMethodKey::PASSWORD],
            [HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK, AuthMethodKey::MAGIC_LINK],
            [HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK, AuthMethodKey::MAGIC_LINK],
            [HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE, AuthMethodKey::MAGIC_LINK],
            [HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS, AuthMethodKey::MAGIC_LINK],
            [HilosSignalConstants::HILOS_REQUEST_PHONE_CODE, AuthMethodKey::SMS],
            [HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE, AuthMethodKey::SMS],
            [HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS, AuthMethodKey::PASSKEY],
            [HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM, AuthMethodKey::PASSKEY],
            [HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS, AuthMethodKey::PASSKEY],
            [HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM, AuthMethodKey::PASSKEY],
        ];
    }

    /**
     * @return list<array{string}> The mailed registration code, which serves both registration roads
     */
    public static function registrationCodeActions(): array
    {
        return [
            [HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM],
            [HilosSignalConstants::HILOS_CONFIRM_REGISTER],
        ];
    }

    /**
     * An action is open while its method is on, whatever else is off.
     */
    #[DataProvider('singleMethodActions')]
    public function testAnActionIsOpenWhileItsMethodIsOn(string $action, string $methodKey): void
    {
        AuthMethodTestHilos::mount(self::allBut($methodKey));

        AuthMethodGate::assertActionOpen($action);

        $this->addToAssertionCount(1);
    }

    /**
     * An action is refused once its method is off, with the ordinary action error.
     */
    #[DataProvider('singleMethodActions')]
    public function testAnActionIsRefusedOnceItsMethodIsOff(string $action, string $methodKey): void
    {
        AuthMethodTestHilos::mount($methodKey);

        $this->expectExceptionObject(new ValidationException(AuthMessages::METHOD_TURNED_OFF));

        AuthMethodGate::assertActionOpen($action);
    }

    /**
     * The registration code stays open while either road it serves is on.
     */
    #[DataProvider('registrationCodeActions')]
    public function testTheRegistrationCodeIsOpenWhileEitherRoadIs(string $action): void
    {
        AuthMethodTestHilos::mount(AuthMethodKey::PASSWORD);
        AuthMethodGate::assertActionOpen($action);

        AuthMethodTestHilos::mount(AuthMethodKey::MAGIC_LINK);
        AuthMethodGate::assertActionOpen($action);

        $this->addToAssertionCount(2);
    }

    /**
     * The registration code is refused once both roads it serves are off.
     */
    #[DataProvider('registrationCodeActions')]
    public function testTheRegistrationCodeIsRefusedOnceBothRoadsAreOff(string $action): void
    {
        AuthMethodTestHilos::mount(AuthMethodKey::PASSWORD . ',' . AuthMethodKey::MAGIC_LINK);

        $this->expectExceptionObject(new ValidationException(AuthMessages::METHOD_TURNED_OFF));

        AuthMethodGate::assertActionOpen($action);
    }

    /**
     * The lookup and a canceled registration belong to no method and pass with every method off but one.
     */
    public function testActionsInNoMethodAlwaysPass(): void
    {
        AuthMethodTestHilos::mount(implode(',', self::ALL_BUT_PROVIDERS));

        AuthMethodGate::assertActionOpen(HilosSignalConstants::HILOS_DETECT_IDENTIFIER);
        AuthMethodGate::assertActionOpen(HilosSignalConstants::HILOS_CANCEL_REGISTRATION);

        $this->addToAssertionCount(2);
    }

    /**
     * A project that declares no directory has nothing to switch, so nothing is locked.
     */
    public function testNothingIsLockedWithoutADirectory(): void
    {
        AuthMethodGate::assertActionOpen(HilosSignalConstants::HILOS_LOGIN);
        AuthMethodGate::assertProviderOpen(OAuthProviderPreset::GITHUB->value);

        $this->addToAssertionCount(2);
    }

    /**
     * A provider is checked by its own key: another provider switched off does not close it.
     */
    public function testAProviderIsOpenWhileItsOwnKeyIsOn(): void
    {
        AuthMethodTestHilos::mount(OAuthProviderPreset::GOOGLE->value);

        AuthMethodGate::assertProviderOpen(OAuthProviderPreset::GITHUB->value);

        $this->addToAssertionCount(1);
    }

    /**
     * A switched-off provider is refused with the ordinary action error.
     */
    public function testASwitchedOffProviderIsRefused(): void
    {
        AuthMethodTestHilos::mount(OAuthProviderPreset::GITHUB->value);

        $this->expectExceptionObject(new ValidationException(AuthMessages::METHOD_TURNED_OFF));

        AuthMethodGate::assertProviderOpen(OAuthProviderPreset::GITHUB->value);
    }

    /**
     * A provider the project never declared is left to its command to refuse as unknown.
     */
    public function testAnUndeclaredProviderIsLeftToItsCommand(): void
    {
        AuthMethodTestHilos::mount(null);

        AuthMethodGate::assertProviderOpen('oauth:acme');

        $this->addToAssertionCount(1);
    }

    /**
     * The stored list that leaves exactly one method on.
     *
     * @param string $methodKey Method to leave on
     * @return string Every other built-in method, comma-joined
     */
    private static function allBut(string $methodKey): string
    {
        return implode(',', array_diff(self::ALL_BUT_PROVIDERS, [$methodKey]));
    }
}
