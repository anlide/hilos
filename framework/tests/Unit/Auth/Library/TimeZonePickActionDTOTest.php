<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\TimeZonePickActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The browser chooses a value; its live session alone identifies the person. */
final class TimeZonePickActionDTOTest extends TestCase
{
    public function testChoicesRoundTripExactlyWithoutIdentityFields(): void
    {
        foreach ([null, 'Europe/Warsaw', 'Europe/Kiev', '', ' Europe/Warsaw', 'Unknown/Zone', '+02:00'] as $timeZone) {
            $dto = TimeZonePickActionDTO::fromArray([
                'timeZone' => $timeZone, 'userId' => 99, 'acceptKey' => 'someone-else', 'sessionToken' => 'untrusted',
            ]);

            self::assertSame(['timeZone' => $timeZone], $dto->toArray());
            self::assertSame($timeZone, TimeZonePickActionDTO::fromArray($dto->toArray())->timeZone);
            self::assertSame(HilosSignalConstants::HILOS_TIME_ZONE_PICK, $dto->getAction());
        }
    }

    /** @param array<string, mixed> $payload Malformed browser choice */
    #[DataProvider('invalidPayloads')]
    public function testMissingAndMistypedChoicesAreRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        TimeZonePickActionDTO::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Shape failures, never resets */
    public static function invalidPayloads(): iterable
    {
        yield 'missing' => [[]];
        yield 'integer' => [['timeZone' => 1]];
        yield 'boolean' => [['timeZone' => false]];
        yield 'array' => [['timeZone' => []]];
        yield 'float' => [['timeZone' => 1.5]];
    }

    public function testTheLibraryOwnsTheAuthenticatedActionAndExistingCompletion(): void
    {
        self::assertSame('hilos_time_zone_pick', HilosSignalConstants::HILOS_TIME_ZONE_PICK);
        self::assertSame(TimeZonePickActionDTO::class,
            AbstractUsersLibraryAgent::AGENT_ACTIONS[HilosSignalConstants::HILOS_TIME_ZONE_PICK]);
        self::assertContains(HilosSignalConstants::HILOS_TIME_ZONE_PICK, AbstractUsersLibraryAgent::AUTH_ACTIONS);
        self::assertSame(UserTimeZoneWriteDoneSignalData::class,
            AbstractUsersLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE]);
        self::assertSame([], TimeZonePickActionDTO::SECRET_FIELDS);
    }
}
