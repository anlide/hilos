<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\UserRenameFeedLinkSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserRenameFeedLinkSignalDataTest extends TestCase
{
    public function testCapturedIdsSurviveTwoWireHopsWithoutReplyFields(): void
    {
        $link = new UserRenameFeedLinkSignalData(7, 11, 23);
        $wire = ['userId' => 7, 'renameId' => 11, 'eventId' => 23];
        self::assertSame($wire, $link->toArray());
        for ($hop = 0; $hop < 2; ++$hop) {
            $link = UserRenameFeedLinkSignalData::fromJson($link->toJson());
            self::assertSame($wire, $link->toArray());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid captured ids */
    public static function invalidIds(): iterable
    {
        foreach (['userId', 'renameId', 'eventId'] as $field) {
            foreach ([0, -1, '7', null] as $invalid) {
                yield $field . ':' . json_encode($invalid) => [array_replace(
                    ['userId' => 7, 'renameId' => 11, 'eventId' => 23],
                    [$field => $invalid],
                )];
            }
            $missing = ['userId' => 7, 'renameId' => 11, 'eventId' => 23];
            unset($missing[$field]);
            yield $field . ':missing' => [$missing];
        }
    }

    /** @param array<string, mixed> $wire Broken transport payload */
    #[DataProvider('invalidIds')]
    public function testRefusesANonpositiveMissingOrMistypedId(array $wire): void
    {
        $this->expectException(InvalidFormatException::class);
        UserRenameFeedLinkSignalData::fromArray($wire);
    }
}
