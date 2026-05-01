<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\DTO\PublishedAttachmentInput;
use Demo\Chat\Database\DTO\PublishedAttachmentInputs;
use Demo\Chat\Database\Object\Item\EventAttachment as ObjectEventAttachment;
use Demo\Chat\Hilos;
use Demo\Chat\Http\ChatAttachmentDownloadHandler;
use Hilos\Constants\HilosHttpHeaders;
use Hilos\Constants\HttpConstants;

/**
 * Integration coverage for published event attachment persistence and projection.
 */
final class EventAttachmentsTest extends IntegrationTestCase
{
    public function testMessagePersistsMultipleAttachmentsOutsideEventData(): void
    {
        Hilos::$db->events->actions->deleteAll();
        Hilos::$fs->published['event-attachment-one.txt']->unlink();
        Hilos::$fs->published['event-attachment-two.txt']->unlink();

        try {
            Hilos::$fs->published->create('event-attachment-one.txt')->append('alpha');
            Hilos::$fs->published->create('event-attachment-two.txt')->append('bravo!');
            $sessionToken = bin2hex(random_bytes(16));
            $user = Hilos::$db->users->actions->register($sessionToken);

            $event = Hilos::$db->events->actions->addMessage(
                'with files',
                userId: $user->id,
                attachments: new PublishedAttachmentInputs(
                    new PublishedAttachmentInput('One.txt', 'text/plain', 'event-attachment-one.txt'),
                    new PublishedAttachmentInput('Two.txt', 'text/plain', 'event-attachment-two.txt'),
                ),
            );

            $data = json_decode((string)$event->data, true);
            $this->assertSame(['message' => 'with files'], $data);
            $this->assertSame(2, count($event->attachments));
            $this->assertSame(2, count(Hilos::$db->eventAttachments->forEventId((int)$event->id)));
            $this->assertSame(11, Hilos::$db->eventAttachments->sumPublishedAttachmentBytes());
            $this->assertTrue(Hilos::$db->eventAttachments->hasPublishedFileWithNormalizedFilename('one.txt'));

            $firstAttachment = $event->attachments->first();
            $this->assertNotNull($firstAttachment);
            $this->assertSame($event->id, $firstAttachment->event?->id);
            $this->assertSame('alpha', $firstAttachment->file->read());
            $download = ChatAttachmentDownloadHandler::handle([
                'request' => [
                    'queryParams' => [
                        'id' => (string)$firstAttachment->id,
                        HilosHttpHeaders::HILOS_SESSION_TOKEN => $sessionToken,
                    ],
                ],
                'params' => [],
            ]);
            $this->assertSame(HttpConstants::HTTP_OK, $download[HttpConstants::RESPONSE_KEY_STATUS]);
            $this->assertSame('alpha', $download[HttpConstants::RESPONSE_KEY_BODY]);

            $frontendPayload = $event->toArray(toFrontend: true);
            $this->assertCount(2, $frontendPayload['attachments']);
            $this->assertSame('One.txt', $frontendPayload['attachments'][0][ObjectEventAttachment::filename]);
            $this->assertArrayNotHasKey(ObjectEventAttachment::storedName, $frontendPayload['attachments'][0]);
        } finally {
            Hilos::$db->events->actions->deleteAll();
            Hilos::$fs->published['event-attachment-one.txt']->unlink();
            Hilos::$fs->published['event-attachment-two.txt']->unlink();
        }
    }
}
