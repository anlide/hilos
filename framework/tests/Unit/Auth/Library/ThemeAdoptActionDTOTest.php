<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\ThemeAdoptActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Theme adoption carries only a valid browser choice and requires a signed-in session. */
final class ThemeAdoptActionDTOTest extends TestCase
{
    /** All three positions round-trip without accepting a client-supplied person. */
    public function testThemeChoicesRoundTripWithoutIdentityFields(): void
    {
        foreach (['light', 'dark', 'system'] as $pick) {
            $dto = ThemeAdoptActionDTO::fromArray(['themePick' => $pick, 'userId' => 99, 'accountId' => 88]);

            self::assertSame(['themePick' => $pick], $dto->toArray());
            self::assertSame($pick, ThemeAdoptActionDTO::fromArray($dto->toArray())->themePick);
            self::assertSame(HilosSignalConstants::HILOS_THEME_ADOPT, $dto->getAction());
        }
    }

    /**
     * @param array<string, mixed> $payload Invalid browser payload
     */
    #[DataProvider('invalidChoices')]
    public function testInvalidChoicesAreRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        ThemeAdoptActionDTO::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed or unsupported choices */
    public static function invalidChoices(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['themePick' => null]];
        yield 'auto' => [['themePick' => 'auto']];
        yield 'empty' => [['themePick' => '']];
        yield 'number' => [['themePick' => 1]];
        yield 'boolean' => [['themePick' => true]];
    }

    /** The same library owns the action and the person's completion. */
    public function testTheLibraryDeclaresAdoptionAndRequiresAuthentication(): void
    {
        self::assertSame(ThemeAdoptActionDTO::class, AbstractUsersLibraryAgent::AGENT_ACTIONS[HilosSignalConstants::HILOS_THEME_ADOPT]);
        self::assertContains(HilosSignalConstants::HILOS_THEME_ADOPT, AbstractUsersLibraryAgent::AUTH_ACTIONS);
        self::assertSame([], ThemeAdoptActionDTO::SECRET_FIELDS);
    }
}
