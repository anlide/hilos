<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MessageActionDTO (message payload parsing and validation).
 */
final class MessageActionDTOTest extends TestCase
{
    /**
     * fromArray reads and trims the top-level content field.
     */
    public function testFromArrayUsesContentKey(): void
    {
        $dto = MessageActionDTO::fromArray(['content' => '  hello  ']);
        $this->assertSame('hello', $dto->content);
    }

    /**
     * fromArray supports legacy shape data.message when content is omitted.
     */
    public function testFromArraySupportsLegacyDataMessageShape(): void
    {
        $dto = MessageActionDTO::fromArray([
            'data' => ['message' => '  legacy  '],
        ]);
        $this->assertSame('legacy', $dto->content);
    }

    /**
     * Top-level content wins when both content and data.message are present.
     */
    public function testContentKeyTakesPrecedenceOverLegacyData(): void
    {
        $dto = MessageActionDTO::fromArray([
            'content' => 'primary',
            'data' => ['message' => 'ignored'],
        ]);
        $this->assertSame('primary', $dto->content);
    }

    /**
     * Content that is not a string is refused, not coerced into a blank message.
     */
    public function testFromArrayRefusesNonStringContent(): void
    {
        $this->expectException(InvalidFormatException::class);

        MessageActionDTO::fromArray(['content' => 123]);
    }

    /**
     * isValid is false for whitespace-only content without attachments.
     */
    public function testIsValidFalseWhenContentEmpty(): void
    {
        $dto = MessageActionDTO::fromArray(['content' => '   ']);
        $this->assertFalse($dto->isValid());
    }

    /**
     * A message without text is valid when it carries a file.
     */
    public function testIsValidTrueWhenOnlyAttachmentsAreSent(): void
    {
        $dto = MessageActionDTO::fromArray(['content' => '', 'attachments' => ['u1']]);
        $this->assertTrue($dto->isValid());
    }

    /**
     * Without the key a message carries no file; with it, the upload ids in the order sent.
     */
    public function testAttachmentsDefaultToNoneAndKeepTheirOrder(): void
    {
        $this->assertSame([], MessageActionDTO::fromArray(['content' => 'hi'])->attachments);
        $this->assertSame(
            ['u2', 'u1'],
            MessageActionDTO::fromArray(['content' => 'hi', 'attachments' => ['u2', 'u1']])->attachments,
        );
        $this->assertSame(
            ['u3'],
            MessageActionDTO::fromArray(['data' => ['message' => 'legacy'], 'attachments' => ['u3']])->attachments,
        );
    }

    /**
     * An attachment id that is not a string is refused rather than dropped from the list.
     */
    public function testFromArrayRefusesANonStringAttachment(): void
    {
        $this->expectException(InvalidFormatException::class);

        MessageActionDTO::fromArray(['content' => 'hi', 'attachments' => ['u1', 7]]);
    }

    /**
     * A list keyed by name is not the list of uploads the client sends.
     */
    public function testFromArrayRefusesAKeyedAttachmentMap(): void
    {
        $this->expectException(InvalidFormatException::class);

        MessageActionDTO::fromArray(['content' => 'hi', 'attachments' => ['first' => 'u1']]);
    }

    /**
     * isValid is true when trimmed content is non-empty.
     */
    public function testIsValidTrueWhenContentNonEmpty(): void
    {
        $dto = MessageActionDTO::fromArray(['content' => 'ok']);
        $this->assertTrue($dto->isValid());
    }

    /**
     * toArray exposes content and the attached upload ids for transport.
     */
    public function testToArrayRoundTripShape(): void
    {
        $dto = new MessageActionDTO('text', ['u1']);
        $this->assertSame([
            'content' => 'text',
            'attachments' => ['u1'],
        ], $dto->toArray());
        $this->assertSame(['u1'], MessageActionDTO::fromArray($dto->toArray())->attachments);
    }
}
