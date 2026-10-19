<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\DTO\OAuthResumeReplyDTO;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OAuthResumeReplyDTOTest extends TestCase
{
    public function testRoundTripPreservesArbitraryServerWords(): void
    {
        $message = " Server-authored refusal.\nKeep whitespace. ";
        $reply = new OAuthResumeReplyDTO(false, $message);

        self::assertSame(['known' => false, 'message' => $message], $reply->toArray());
        self::assertSame($reply->toArray(), OAuthResumeReplyDTO::fromArray($reply->toArray())->toArray());
    }

    public function testKnownReplyCarriesExplicitNull(): void
    {
        $reply = new OAuthResumeReplyDTO(true, null);

        self::assertSame(['known' => true, 'message' => null], $reply->toArray());
        self::assertSame($reply->toArray(), OAuthResumeReplyDTO::fromArray($reply->toArray())->toArray());
    }

    /** @return list<array{array<string, mixed>}> Incomplete or mistyped replies */
    public static function malformedReplies(): array
    {
        return [
            [['known' => false]],
            [['known' => true]],
            [['known' => false, 'message' => 42]],
            [['known' => false, 'message' => false]],
            [['known' => false, 'message' => []]],
            [['message' => null]],
            [['known' => 'false', 'message' => null]],
        ];
    }

    /** @param array<string, mixed> $payload Incomplete or mistyped reply */
    #[DataProvider('malformedReplies')]
    public function testRefusesMalformedReply(array $payload): void
    {
        self::expectException(InvalidFormatException::class);

        OAuthResumeReplyDTO::fromArray($payload);
    }
}
