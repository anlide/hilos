<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\DTO\LegalConsentReplyDTO;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalConsentReplyDTOTest extends TestCase
{
    /** Both messages survive serialization verbatim beside the existing content. */
    public function testRoundTripPreservesArbitraryServerWords(): void
    {
        $reply = new LegalConsentReplyDTO(
            'checkbox',
            [['document' => 'terms', 'revision' => ['revisionId' => 'terms-v2'], 'clauses' => []]],
            " Publication is paused.\nPlease return later. ",
            " New revision ready.\nReview it again. ",
        );

        self::assertSame([
            'form' => 'checkbox',
            'documents' => [['document' => 'terms', 'revision' => ['revisionId' => 'terms-v2'], 'clauses' => []]],
            'unpublishedMessage' => " Publication is paused.\nPlease return later. ",
            'revisedMessage' => " New revision ready.\nReview it again. ",
        ], $reply->toArray());
        self::assertSame($reply->toArray(), LegalConsentReplyDTO::fromArray($reply->toArray())->toArray());
    }

    /** No documents still carries both required messages. */
    public function testEmptyDocumentsPreserveMessages(): void
    {
        $reply = new LegalConsentReplyDTO('line', [], 'Not published here.', 'Review the new revision.');

        self::assertSame($reply->toArray(), LegalConsentReplyDTO::fromArray($reply->toArray())->toArray());
        self::assertSame([], $reply->documents);
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing and mistyped required fields */
    public static function malformedReplies(): iterable
    {
        $payload = [
            'form' => 'checkbox',
            'documents' => [],
            'unpublishedMessage' => 'Not published here.',
            'revisedMessage' => 'Review the new revision.',
        ];
        foreach (['form', 'documents', 'unpublishedMessage', 'revisedMessage'] as $field) {
            $missing = $payload;
            unset($missing[$field]);
            yield $field . ' missing' => [$missing];
        }
        foreach (['unpublishedMessage', 'revisedMessage'] as $field) {
            foreach ([null, false, 42, 1.5, []] as $index => $value) {
                yield $field . ' type ' . $index => [array_replace($payload, [$field => $value])];
            }
        }
    }

    /** @param array<string, mixed> $payload Invalid serialized reply */
    #[DataProvider('malformedReplies')]
    public function testRefusesMalformedReply(array $payload): void
    {
        self::expectException(InvalidFormatException::class);

        LegalConsentReplyDTO::fromArray($payload);
    }
}
