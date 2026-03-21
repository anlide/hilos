<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Page\DTO\MessageActionDTO;
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
     * Non-string content is treated as an empty string after coercion.
     */
    public function testFromArrayTreatsNonStringContentAsEmpty(): void
    {
        $dto = MessageActionDTO::fromArray(['content' => 123]);
        $this->assertSame('', $dto->content);
    }

    /**
     * isValid is false for whitespace-only content.
     */
    public function testIsValidFalseWhenContentEmpty(): void
    {
        $dto = MessageActionDTO::fromArray(['content' => '   ']);
        $this->assertFalse($dto->isValid());
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
     * toArray exposes the expected single content key for transport.
     */
    public function testToArrayRoundTripShape(): void
    {
        $dto = new MessageActionDTO('text');
        $this->assertSame(['content' => 'text'], $dto->toArray());
    }
}
