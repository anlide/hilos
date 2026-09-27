<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\DTO\CompleteRegistrationPasskeyActionDTO;
use Hilos\Auth\Library\DTO\RegistrationPasskeyOptionsActionDTO;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The wire distinguishes an absent address from an empty or mistyped one (HIL-1106). */
final class RegistrationPasskeyActionDTOTest extends TestCase
{
    /**
     * @param array<string, mixed> $address Optional identifier field
     * @param ?string $expected Expected identifier
     * @throws InvalidFormatException When the payload is invalid
     */
    #[DataProvider('addresses')]
    public function testBothActionsPreserveTheOptionalAddress(array $address, ?string $expected): void
    {
        $options = RegistrationPasskeyOptionsActionDTO::fromArray($address);
        $this->assertSame($expected, $options->identifier);
        $this->assertSame($expected, RegistrationPasskeyOptionsActionDTO::fromArray($options->toArray())->identifier);

        $complete = CompleteRegistrationPasskeyActionDTO::fromArray($address + [
            'signedChallenge' => 'signed-challenge',
            'attestationObject' => 'attestation',
            'clientDataJson' => 'client-data',
            'transports' => ['internal'],
            'userAgent' => 'browser',
        ]);
        $this->assertSame($expected, $complete->identifier);
        $this->assertSame($complete->toArray(), CompleteRegistrationPasskeyActionDTO::fromArray($complete->toArray())->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>, ?string}> Wire representations of an address */
    public static function addresses(): iterable
    {
        yield 'omitted' => [[], null];
        yield 'null' => [['identifier' => null], null];
        yield 'address' => [['identifier' => 'person@example.test'], 'person@example.test'];
        yield 'empty is not absent' => [['identifier' => ''], ''];
    }

    public function testOptionsRefuseAMistypedAddress(): void
    {
        $this->expectException(InvalidFormatException::class);
        RegistrationPasskeyOptionsActionDTO::fromArray(['identifier' => 42]);
    }

    public function testCompletionRefusesAMistypedAddress(): void
    {
        $this->expectException(InvalidFormatException::class);
        CompleteRegistrationPasskeyActionDTO::fromArray([
            'identifier' => 42,
            'signedChallenge' => 'signed-challenge',
            'attestationObject' => 'attestation',
            'clientDataJson' => 'client-data',
        ]);
    }
}
