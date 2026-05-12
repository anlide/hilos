<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\DTO\PublishedAttachmentInput;
use Demo\Chat\Database\DTO\PublishedAttachmentInputs;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\EventAttachment as ObjectEventAttachment;
use Demo\Chat\Database\Object\Item\EventMessage as ObjectEventMessage;
use Demo\Chat\Hilos;
use Demo\Chat\Http\ChatAttachmentDownloadHandler;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosHttpHeaders;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration coverage for published event attachment persistence and projection.
 */
final class EventAttachmentsTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testMessagePersistsMultipleAttachmentsOutsideEventData(): void
    {
        Hilos::$db->events->actions->deleteAll();
        Hilos::$fs->published['event-attachment-one.txt']->unlink();
        Hilos::$fs->published['event-attachment-two.txt']->unlink();

        try {
            Hilos::$fs->published->create('event-attachment-one.txt')->append('alpha');
            Hilos::$fs->published->create('event-attachment-two.txt')->append('bravo!');
            $sessionToken = RandomHelper::hex(16);
            $user = Hilos::$db->users->actions->register($sessionToken);

            $event = Hilos::$db->events->actions->addMessage(
                'with files',
                userId: $user->id,
                attachments: new PublishedAttachmentInputs(
                    new PublishedAttachmentInput('One.txt', 'text/plain', 'event-attachment-one.txt'),
                    new PublishedAttachmentInput('Two.txt', 'text/plain', 'event-attachment-two.txt'),
                ),
            );

            $this->assertSame('with files', $event->eventMessage?->message);
            $this->assertSame($user->id, $event->eventMessage?->authorUserId);
            $this->assertNull($event->eventMessage?->authorBotId);
            $this->assertSame(2, count($event->attachments));
            $this->assertSame(2, count(Hilos::$db->eventAttachments->forEventId((int)$event->id)));
            $this->assertSame(11, Hilos::$db->eventAttachments->sumPublishedAttachmentBytes());
            $this->assertTrue(Hilos::$db->eventAttachments->hasPublishedFileWithNormalizedFilename('one.txt'));

            $messageDetail = $event->eventMessage;
            $this->assertNotNull($messageDetail);
            $this->assertSame($event->id, $messageDetail->event?->id);
            $this->assertSame($user->id, $messageDetail->authorUser?->id);
            $this->assertNull($messageDetail->authorBot);
            $this->assertSame(2, count($messageDetail->attachments));

            $firstAttachment = $event->attachments->first();
            $this->assertNotNull($firstAttachment);
            $this->assertSame($event->id, $firstAttachment->eventMessage?->eventId);
            $this->assertSame('alpha', $firstAttachment->file->read());
            $download = ChatAttachmentDownloadHandler::handle([
                'request' => [
                    'queryParams' => new RequestQueryParams([
                        'id' => (string)$firstAttachment->id,
                        HilosHttpHeaders::HILOS_SESSION_TOKEN => $sessionToken,
                    ]),
                ],
                'params' => [],
            ]);
            $this->assertSame(HttpConstants::HTTP_OK, $download[HttpConstants::RESPONSE_KEY_STATUS]);
            $this->assertSame('alpha', $download[HttpConstants::RESPONSE_KEY_BODY]);

            $frontendPayload = $event->toArray(toFrontend: true);
            $this->assertSame('with files', $frontendPayload[ChatDbContext::eventMessage][ObjectEventMessage::message]);
            $this->assertSame($user->id, $frontendPayload[ChatDbContext::eventMessage][ObjectEventMessage::authorUserId]);
            $this->assertCount(2, $frontendPayload['attachments']);
            $this->assertSame('One.txt', $frontendPayload['attachments'][0][ObjectEventAttachment::filename]);
            $this->assertArrayNotHasKey(ObjectEventAttachment::storedName, $frontendPayload['attachments'][0]);
        } finally {
            Hilos::$db->events->actions->deleteAll();
            Hilos::$fs->published['event-attachment-one.txt']->unlink();
            Hilos::$fs->published['event-attachment-two.txt']->unlink();
        }
    }

    public function testPublishConnectionDraftsMovesQuarantineFilesAndReturnsMetadata(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$fs->quarantine['draft-publish.txt']->unlink();
        Hilos::$fs->published['draft-publish.txt']->unlink();

        try {
            Hilos::$rt->connections->actions->register('publish-ak', 1);
            Hilos::$fs->quarantine->create('draft-publish.txt')->append('draft-body');
            Hilos::$rt->attachmentDrafts->actions->create(
                'draft-publish',
                'publish-ak',
                1,
                'draft-publish.txt',
                'Original.txt',
                'text/plain',
                10,
                'original.txt',
                time(),
            );

            $connection = Hilos::$rt->connections['publish-ak'];
            $this->assertNotNull($connection);
            $inputs = Hilos::$rt->attachmentDrafts->actions->publishForConnection($connection);

            $this->assertNotNull($inputs);
            $items = iterator_to_array($inputs);
            $this->assertCount(1, $items);
            $this->assertSame('Original.txt', $items[0]->filename);
            $this->assertSame('text/plain', $items[0]->mimeType);
            $this->assertSame('draft-publish.txt', $items[0]->storedName);
            $this->assertFalse(Hilos::$fs->quarantine['draft-publish.txt']->exists());
            $this->assertTrue(Hilos::$fs->published['draft-publish.txt']->exists());
            $this->assertSame('draft-body', Hilos::$fs->published['draft-publish.txt']->read());
            $this->assertSame(0, count(Hilos::$rt->attachmentDrafts->forAcceptKey('publish-ak')));
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
            Hilos::$fs->quarantine['draft-publish.txt']->unlink();
            Hilos::$fs->published['draft-publish.txt']->unlink();
        }
    }

    public function testPublishConnectionDraftsReturnsNullWhenQuarantineFileIsMissing(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$fs->quarantine['draft-missing.txt']->unlink();
        Hilos::$fs->published['draft-missing.txt']->unlink();

        try {
            Hilos::$rt->connections->actions->register('publish-missing-ak', 1);
            Hilos::$rt->attachmentDrafts->actions->create(
                'draft-missing',
                'publish-missing-ak',
                1,
                'draft-missing.txt',
                'Missing.txt',
                'text/plain',
                12,
                'missing.txt',
                time(),
            );

            $connection = Hilos::$rt->connections['publish-missing-ak'];
            $this->assertNotNull($connection);
            $inputs = Hilos::$rt->attachmentDrafts->actions->publishForConnection($connection);

            $this->assertNull($inputs);
            $this->assertSame(0, count(Hilos::$rt->attachmentDrafts->forAcceptKey('publish-missing-ak')));
            $this->assertFalse(Hilos::$fs->published['draft-missing.txt']->exists());
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
            Hilos::$fs->quarantine['draft-missing.txt']->unlink();
            Hilos::$fs->published['draft-missing.txt']->unlink();
        }
    }
}
