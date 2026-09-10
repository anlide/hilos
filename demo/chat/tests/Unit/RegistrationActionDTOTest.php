<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Hilos\Auth\Library\DTO\CompleteRegistrationActionDTO;
use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RequestRegisterConfirmActionDTO;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the four registration payloads (HIL-415, HIL-825): the submit
 * (email), the resend (email), the confirm (email + code), and the save that
 * creates the account (password). Locks the parse-trim shape the handlers rely on,
 * and the shape changes the leaves made - the submit lost its password to the save
 * that comes after the code, and the resend and confirm gained the address, since
 * the session sending them has no account yet for the server to resolve one from.
 */
final class RegistrationActionDTOTest extends TestCase
{
    /**
     * The submit DTO trims the email, and that address is the whole payload.
     */
    public function testRegisterFromArrayTrimsEmail(): void
    {
        $dto = RegisterActionDTO::fromArray(['email' => '  Person@Example.test  ']);

        $this->assertSame('Person@Example.test', $dto->email);
        $this->assertSame(['email' => 'Person@Example.test'], $dto->toArray());
    }

    /**
     * A password sent with the submit is not read: it left this payload with HIL-825,
     * and an old client that still sends one must not have it stored for an address
     * nobody has proved yet.
     */
    public function testRegisterCarriesNoPassword(): void
    {
        $dto = RegisterActionDTO::fromArray([
            'email' => 'person@example.test',
            'password' => 'correct horse battery',
            'confirmPassword' => 'something else entirely',
        ]);

        $this->assertSame(['email' => 'person@example.test'], $dto->toArray());
        $this->assertFalse(property_exists($dto, 'password'));
        $this->assertFalse(property_exists($dto, 'confirmPassword'));
    }

    /**
     * A submit with no address at all is refused, not read as a blank one.
     */
    public function testRegisterFromArrayRefusesAPayloadWithoutAnEmail(): void
    {
        $this->expectException(InvalidFormatException::class);

        RegisterActionDTO::fromArray([]);
    }

    /**
     * The save DTO carries the password verbatim, and names no address at all.
     *
     * The address is read off the hold this browser proved, so a payload cannot point
     * the save at an account whose mailbox somebody else answered (HIL-825).
     */
    public function testCompleteRegistrationKeepsThePasswordVerbatimAndNamesNoAddress(): void
    {
        $dto = CompleteRegistrationActionDTO::fromArray([
            'password' => '  spaced  ',
            'email' => 'somebody@example.test',
        ]);

        $this->assertSame('  spaced  ', $dto->password);
        $this->assertSame(['password' => '  spaced  '], $dto->toArray());
        $this->assertFalse(property_exists($dto, 'email'));
    }

    /**
     * A save with no password at all is refused, not read as a blank one.
     */
    public function testCompleteRegistrationRefusesAPayloadWithoutAPassword(): void
    {
        $this->expectException(InvalidFormatException::class);

        CompleteRegistrationActionDTO::fromArray([]);
    }

    /**
     * The resend DTO carries the address, trimmed.
     */
    public function testResendFromArrayTrimsEmail(): void
    {
        $this->assertSame(
            'person@example.test',
            RequestRegisterConfirmActionDTO::fromArray(['email' => ' person@example.test '])->email,
        );
    }

    /**
     * A resend with no address is refused: it used to take none, and a blank one
     * would ask the reservation layer to hold nothing.
     */
    public function testResendFromArrayRefusesAPayloadWithoutAnEmail(): void
    {
        $this->expectException(InvalidFormatException::class);

        RequestRegisterConfirmActionDTO::fromArray([]);
    }

    /**
     * The confirm DTO carries both the address and the code, both trimmed.
     */
    public function testConfirmFromArrayTrimsEmailAndCode(): void
    {
        $dto = ConfirmRegisterActionDTO::fromArray(['email' => ' person@example.test ', 'code' => ' 424242 ']);

        $this->assertSame('person@example.test', $dto->email);
        $this->assertSame('424242', $dto->code);
        $this->assertSame(['email' => 'person@example.test', 'code' => '424242'], $dto->toArray());
    }

    /**
     * A confirm without the address is refused: the code alone names no registration.
     */
    public function testConfirmFromArrayRefusesAPayloadWithoutAnEmail(): void
    {
        $this->expectException(InvalidFormatException::class);

        ConfirmRegisterActionDTO::fromArray(['code' => '424242']);
    }
}
