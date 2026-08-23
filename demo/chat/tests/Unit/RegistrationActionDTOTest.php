<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RequestRegisterConfirmActionDTO;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the three reserve-on-submit registration payloads (HIL-415): the
 * submit (email + password), the resend (email), and the confirm (email + code).
 * Locks the parse-trim shape the handlers rely on, and the two shape changes this
 * leaf made - the submit dropped its password confirmation, and the resend and
 * confirm gained the address, since the session sending them has no account yet
 * for the server to resolve one from.
 */
final class RegistrationActionDTOTest extends TestCase
{
    /**
     * The submit DTO trims the email and passes the password through verbatim.
     */
    public function testRegisterFromArrayTrimsEmailAndKeepsPasswordVerbatim(): void
    {
        $dto = RegisterActionDTO::fromArray(['email' => '  Person@Example.test  ', 'password' => '  spaced  ']);

        $this->assertSame('Person@Example.test', $dto->email);
        $this->assertSame('  spaced  ', $dto->password);
    }

    /**
     * A password confirmation is not part of the payload any more, and sending one
     * changes nothing - the surface has a single password field (HIL-412).
     */
    public function testRegisterCarriesNoPasswordConfirmation(): void
    {
        $dto = RegisterActionDTO::fromArray([
            'email' => 'person@example.test',
            'password' => 'correct horse battery',
            'confirmPassword' => 'something else entirely',
        ]);

        $this->assertSame(['email' => 'person@example.test', 'password' => 'correct horse battery'], $dto->toArray());
        $this->assertFalse(property_exists($dto, 'confirmPassword'));
    }

    /**
     * A submit with no password at all is refused, not read as a blank one.
     */
    public function testRegisterFromArrayRefusesAPayloadWithoutAPassword(): void
    {
        $this->expectException(InvalidFormatException::class);

        RegisterActionDTO::fromArray(['email' => 'person@example.test']);
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
