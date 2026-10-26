<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Pages\Moderation\DTO\HilosModerationKindSubscribeParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Route shape is independent of whether the code directory publishes the key. */
final class HilosModerationKindSubscribeParamsTest extends TestCase
{
    public function testRequiredStringPreservesUnknownDomainKeyWithoutALookup(): void
    {
        self::assertSame('kind', HilosPageRouteParams::HILOS_MODERATION_KIND);
        foreach (['hilos_profile_name', 'unknown_kind', '0', 'Mixed case'] as $kind) {
            self::assertSame($kind, HilosModerationKindSubscribeParams::fromPageRouteParams(new PageRouteParams([
                HilosPageRouteParams::HILOS_MODERATION_KIND => $kind,
            ]))->kind);
        }
    }

    /** @return list<array{array<string, mixed>, class-string}> Missing and malformed addresses */
    public static function invalidParams(): array
    {
        return [
            [[], MissingPageRouteParamException::class],
            [['kind' => ''], MissingPageRouteParamException::class],
            [['kind' => null], MissingPageRouteParamException::class],
            [['kind' => 1], MissingPageRouteParamException::class],
            [['kind' => true], MissingPageRouteParamException::class],
            [['kind' => []], MissingPageRouteParamException::class],
        ];
    }

    /**
     * @param array<string, mixed> $params Invalid route parameters
     * @param class-string $exception Expected refusal
     */
    #[DataProvider('invalidParams')]
    public function testMissingAndMalformedKindsAreRefused(array $params, string $exception): void
    {
        $this->expectException($exception);
        HilosModerationKindSubscribeParams::fromPageRouteParams(new PageRouteParams($params));
    }
}
