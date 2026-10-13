<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\ThemePickActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** An explicit theme choice carries only a valid browser choice and requires a signed-in session. */
final class ThemePickActionDTOTest extends TestCase
{
    /** All three positions round-trip without accepting a client-supplied person. */
    public function testThemeChoicesRoundTripWithoutIdentityFields(): void
    {
        foreach (['light', 'dark', 'system'] as $pick) {
            $dto = ThemePickActionDTO::fromArray(['themePick' => $pick, 'userId' => 99, 'accountId' => 88]);

            self::assertSame(['themePick' => $pick], $dto->toArray());
            self::assertSame($pick, ThemePickActionDTO::fromArray($dto->toArray())->themePick);
            self::assertSame(HilosSignalConstants::HILOS_THEME_PICK, $dto->getAction());
        }
    }

    /**
     * @param array<string, mixed> $payload Invalid browser payload
     */
    #[DataProvider('invalidChoices')]
    public function testInvalidChoicesAreRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        ThemePickActionDTO::fromArray($payload);
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
    public function testTheLibraryDeclaresExplicitChoiceAndRequiresAuthentication(): void
    {
        self::assertSame(ThemePickActionDTO::class, AbstractUsersLibraryAgent::AGENT_ACTIONS[HilosSignalConstants::HILOS_THEME_PICK]);
        self::assertContains(HilosSignalConstants::HILOS_THEME_PICK, AbstractUsersLibraryAgent::AUTH_ACTIONS);
        self::assertSame(UserThemePickWriteDoneSignalData::class,
            AbstractUsersLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_USER_THEME_PICK_DONE]);
        self::assertSame([], ThemePickActionDTO::SECRET_FIELDS);
    }
}
