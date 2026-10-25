<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications\DTO;

use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Pages\Communications\DTO\HilosChannelPageSubscribeParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HilosChannelPageSubscribeParamsTest extends TestCase
{
    public function testParsingDoesNotCheckRegistryMembershipOrNormalizeTheKey(): void
    {
        foreach (['email', 'unknown-channel', ' email ', '0'] as $channel) {
            self::assertSame(
                $channel,
                HilosChannelPageSubscribeParams::fromPageRouteParams(new PageRouteParams(['channelId' => $channel]))->channelId,
            );
        }
    }

    /** @param array<string, mixed> $params Missing or malformed route parameters */
    #[DataProvider('missingAddresses')]
    public function testMissingOrMalformedAddressUsesTheRequireStringContract(array $params): void
    {
        $this->expectException(MissingPageRouteParamException::class);

        HilosChannelPageSubscribeParams::fromPageRouteParams(new PageRouteParams($params));
    }

    /** @return iterable<string, array{array<string, mixed>}> Non-address values */
    public static function missingAddresses(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['channelId' => '']];
        yield 'null' => [['channelId' => null]];
        yield 'integer' => [['channelId' => 1]];
        yield 'array' => [['channelId' => ['email']]];
    }
}
