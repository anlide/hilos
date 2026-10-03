<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\LLM;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\DTO\MessageImage;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    /** A picture survives message serialization without changing its bytes. */
    public function testRoundTripsPictures(): void
    {
        $message = new Message(Message::ROLE_USER, 'What is here?', [
            new MessageImage('image/jpeg', 'AAEC'),
            new MessageImage('image/png', 'AQID'),
        ]);

        $this->assertEquals($message, Message::fromArray($message->toArray()));
        $this->assertSame($message->toArray(), Message::toProviderFormat($message));
    }

    /** An ordinary text turn keeps its previous wire shape. */
    public function testTextOnlyMessageOmitsImages(): void
    {
        $message = new Message(Message::ROLE_USER, 'Hello');

        $this->assertSame([
            LLMApiConstants::KEY_ROLE => Message::ROLE_USER,
            LLMApiConstants::KEY_CONTENT => 'Hello',
        ], $message->toArray());
        $this->assertSame([], Message::fromArray($message->toArray())->images);
    }

    /** A malformed image is refused at the DTO boundary. */
    public function testRejectsMalformedImage(): void
    {
        $this->expectException(InvalidFormatException::class);
        Message::fromArray([
            LLMApiConstants::KEY_CONTENT => 'Look',
            LLMApiConstants::KEY_IMAGES => [['mimeType' => 'image/jpeg']],
        ]);
    }
}
