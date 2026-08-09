<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Pages\DTO\Profile\ConfirmSmsAddCodeActionDTO;
use Demo\Chat\Pages\DTO\Profile\RequestSmsAddCodeActionDTO;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the profile add-phone action DTOs (HIL-403): the two-step wizard's
 * request (phone) and confirm (phone + code) payloads. Locks the parse-trim shape
 * the handlers rely on; the owning user is never carried in these payloads.
 */
final class ProfileAddSmsActionDTOTest extends TestCase
{
    /**
     * The request DTO trims the phone it was sent.
     */
    public function testRequestFromArrayTrimsPhone(): void
    {
        $this->assertSame('+15551234', RequestSmsAddCodeActionDTO::fromArray(['phone' => '  +15551234  '])->phone);
    }

    /**
     * A payload with no phone at all is refused, not read as a blank one.
     */
    public function testRequestFromArrayRefusesAPayloadWithoutAPhone(): void
    {
        $this->expectException(InvalidFormatException::class);

        RequestSmsAddCodeActionDTO::fromArray([]);
    }

    /**
     * A phone that is not a string is refused the same way an absent one is.
     */
    public function testRequestFromArrayRefusesANonStringPhone(): void
    {
        $this->expectException(InvalidFormatException::class);

        RequestSmsAddCodeActionDTO::fromArray(['phone' => 123]);
    }

    /**
     * The request DTO is valid only with a non-empty phone.
     */
    public function testRequestIsValidRequiresPhone(): void
    {
        $this->assertTrue(RequestSmsAddCodeActionDTO::fromArray(['phone' => '+15551234'])->isValid());
        $this->assertFalse(RequestSmsAddCodeActionDTO::fromArray(['phone' => '   '])->isValid());
    }

    /**
     * The request DTO round-trips its phone through toArray.
     */
    public function testRequestToArrayShape(): void
    {
        $this->assertSame(['phone' => '+15551234'], new RequestSmsAddCodeActionDTO('+15551234')->toArray());
    }

    /**
     * The confirm DTO trims both fields.
     */
    public function testConfirmFromArrayTrimsPhoneAndCode(): void
    {
        $dto = ConfirmSmsAddCodeActionDTO::fromArray(['phone' => '  +15551234  ', 'code' => '  123456  ']);
        $this->assertSame('+15551234', $dto->phone);
        $this->assertSame('123456', $dto->code);
    }

    /**
     * A confirm payload whose fields are not strings is refused.
     */
    public function testConfirmFromArrayRefusesNonStringFields(): void
    {
        $this->expectException(InvalidFormatException::class);

        ConfirmSmsAddCodeActionDTO::fromArray(['phone' => null, 'code' => 42]);
    }

    /**
     * The confirm DTO is valid only with a non-empty phone and code.
     */
    public function testConfirmIsValidRequiresPhoneAndCode(): void
    {
        $this->assertTrue(ConfirmSmsAddCodeActionDTO::fromArray(['phone' => '+15551234', 'code' => '123456'])->isValid());
        $this->assertFalse(ConfirmSmsAddCodeActionDTO::fromArray(['phone' => '+15551234', 'code' => ''])->isValid());
        $this->assertFalse(ConfirmSmsAddCodeActionDTO::fromArray(['phone' => '', 'code' => '123456'])->isValid());
    }

    /**
     * The confirm DTO round-trips its fields through toArray.
     */
    public function testConfirmToArrayShape(): void
    {
        $this->assertSame(
            ['phone' => '+15551234', 'code' => '123456'],
            new ConfirmSmsAddCodeActionDTO('+15551234', '123456')->toArray(),
        );
    }
}
