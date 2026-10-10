<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Registration;

use Hilos\Auth\Library\DTO\CompleteRegistrationActionDTO;
use Hilos\Auth\Library\DTO\CompleteRegistrationPasskeyActionDTO;
use Hilos\Auth\Library\DTO\CompleteRegistrationPasswordlessActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkCodeActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\OAuthCreateAccountActionDTO;
use Hilos\Auth\Registration\RegistrationThemePick;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The account-creating submit carries the guest's current theme choice (HIL-1427). */
final class RegistrationThemePickTest extends TestCase
{
    public function testAnAbsentChoiceRemainsAbsent(): void
    {
        self::assertNull(RegistrationThemePick::readPayload(null));
    }

    /** @param string $choice One of the theme positions */
    #[DataProvider('choices')]
    public function testEachThemePositionIsAccepted(string $choice): void
    {
        self::assertSame($choice, RegistrationThemePick::readPayload($choice));
    }

    /** @return iterable<string, array{string}> The three positions the theme catalog accepts */
    public static function choices(): iterable
    {
        yield 'light' => ['light'];
        yield 'dark' => ['dark'];
        yield 'system' => ['system'];
    }

    /** @param string $choice A malformed position */
    #[DataProvider('invalidChoices')]
    public function testAnUnknownChoiceIsRefused(string $choice): void
    {
        $this->expectException(ValidationException::class);
        RegistrationThemePick::readPayload($choice);
    }

    /** @return iterable<string, array{string}> Values that cannot create an account */
    public static function invalidChoices(): iterable
    {
        yield 'auto' => ['auto'];
        yield 'capitalized' => ['Dark'];
        yield 'empty' => [''];
    }

    /**
     * @param class-string<ActionPayloadDTO> $dtoClass One of the account-creating actions
     * @param array<string, mixed> $payload Required fields other than the theme choice
     */
    #[DataProvider('accountCreatingActions')]
    public function testEachCreatingActionCarriesTheChoiceAndOmission(
        string $dtoClass,
        array $payload,
    ): void {
        $chosen = $dtoClass::fromArray($payload + [RegistrationThemePick::PAYLOAD_KEY => 'dark']);
        self::assertSame('dark', $chosen->toArray()[RegistrationThemePick::PAYLOAD_KEY]);
        self::assertSame($chosen->toArray(), $dtoClass::fromArray($chosen->toArray())->toArray());

        $unpicked = $dtoClass::fromArray($payload);
        self::assertNull($unpicked->toArray()[RegistrationThemePick::PAYLOAD_KEY]);
    }

    /**
     * @param class-string<ActionPayloadDTO> $dtoClass One of the account-creating actions
     * @param array<string, mixed> $payload Required fields other than the theme choice
     */
    #[DataProvider('accountCreatingActions')]
    public function testEachCreatingActionRejectsAnUnknownChoice(string $dtoClass, array $payload): void
    {
        $this->expectException(ValidationException::class);
        $dtoClass::fromArray($payload + [RegistrationThemePick::PAYLOAD_KEY => 'auto']);
    }

    /** @return iterable<string, array{class-string<ActionPayloadDTO>, array<string, mixed>}> Account-creating action shapes */
    public static function accountCreatingActions(): iterable
    {
        yield 'password' => [CompleteRegistrationActionDTO::class, ['password' => 'test-password']];
        yield 'passwordless' => [CompleteRegistrationPasswordlessActionDTO::class, []];
        yield 'passkey' => [CompleteRegistrationPasskeyActionDTO::class, [
            'identifier' => null,
            'signedChallenge' => 'challenge',
            'attestationObject' => 'attestation',
            'clientDataJson' => 'client-data',
            'transports' => [],
            'userAgent' => null,
        ]];
        yield 'magic link' => [ConfirmMagicLinkActionDTO::class, ['email' => 'person@example.test', 'token' => 'token']];
        yield 'magic code' => [ConfirmMagicLinkCodeActionDTO::class, ['email' => 'person@example.test', 'code' => '123456']];
        yield 'phone code' => [ConfirmPhoneCodeActionDTO::class, ['phone' => '+14155552671', 'code' => '123456']];
        yield 'oauth' => [OAuthCreateAccountActionDTO::class, ['accountToken' => 'token']];
    }
}
