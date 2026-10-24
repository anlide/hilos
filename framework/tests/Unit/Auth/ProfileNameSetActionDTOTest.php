<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth;

use Hilos\Auth\Library\DTO\ProfileNameSetActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The name action requires a string and carries only the normalized name. */
final class ProfileNameSetActionDTOTest extends TestCase
{
    public function testNameRoundTripsWithoutClientIdentity(): void
    {
        $dto = ProfileNameSetActionDTO::fromArray(['name' => 'Zoë Smith', 'userId' => 99]);

        self::assertSame('Zoë Smith', $dto->name);
        self::assertSame(['name' => 'Zoë Smith'], $dto->toArray());
        self::assertSame($dto->name, ProfileNameSetActionDTO::fromArray($dto->toArray())->name);
    }

    public function testNameIsTrimmedWithoutChangingInternalWhitespace(): void
    {
        $dto = ProfileNameSetActionDTO::fromArray(['name' => " \tAlice  Smith\r\n"]);

        self::assertSame('Alice  Smith', $dto->name);
        self::assertSame(['name' => 'Alice  Smith'], $dto->toArray());
    }

    /** Empty input is real input; the command supplies its domain validation. */
    public function testBlankNameReachesTheCommandAsAnEmptyString(): void
    {
        self::assertSame('', ProfileNameSetActionDTO::fromArray(['name' => ''])->name);
        self::assertSame('', ProfileNameSetActionDTO::fromArray(['name' => " \t\n"])->name);
    }

    public function testActionNameAndSecretDeclaration(): void
    {
        self::assertSame('name', ProfileNameSetActionDTO::name);
        self::assertSame('hilos_profile_name_set', HilosSignalConstants::PROFILE_NAME_SET);
        self::assertSame(HilosSignalConstants::PROFILE_NAME_SET, (new ProfileNameSetActionDTO('Alice'))->getAction());
        self::assertSame([], ProfileNameSetActionDTO::SECRET_FIELDS);
    }

    /** @param array<string, mixed> $payload Missing or incorrectly typed name */
    #[DataProvider('invalidPayloads')]
    public function testMissingAndNonStringNamesAreRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);

        ProfileNameSetActionDTO::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid action payloads */
    public static function invalidPayloads(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['name' => null]];
        yield 'integer' => [['name' => 42]];
        yield 'float' => [['name' => 4.2]];
        yield 'true' => [['name' => true]];
        yield 'false' => [['name' => false]];
        yield 'array' => [['name' => ['Alice']]];
        yield 'object' => [['name' => (object)['name' => 'Alice']]];
    }
}
