<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Page\DTO\AttachmentDraftDeleteActionDTO;
use Demo\Chat\Core\Router\DTO\AttachmentDraftsUpdateSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadCompleteSignalData;
use Demo\Chat\Core\Router\DTO\OutboundModerationStateUpdateSignalData;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for attachment draft related wire DTOs.
 */
final class AttachmentDraftSignalDataTest extends TestCase
{
    /**
     * Delete action DTO validates and serializes one draft id.
     */
    public function testAttachmentDraftDeleteActionRoundtrip(): void
    {
        $dto = AttachmentDraftDeleteActionDTO::fromArray(['draftId' => 'draft-1']);

        $this->assertTrue($dto->isValid());
        $this->assertSame('draft-1', $dto->draftId);
        $this->assertSame(['draftId' => 'draft-1'], $dto->toArray());

        $this->assertFalse(AttachmentDraftDeleteActionDTO::fromArray(['draftId' => 1])->isValid());
    }

    /**
     * Attachment draft updates preserve the full draft list through IPC roundtrip.
     */
    public function testAttachmentDraftsUpdateRoundtrip(): void
    {
        $drafts = [[
            'draftId' => 'draft-1',
            'filename' => 'report.pdf',
            'mimeType' => 'application/pdf',
            'size' => 1234,
            'uploadedAt' => 1710000000,
        ]];

        $restored = AttachmentDraftsUpdateSignalData::fromArray(
            (new AttachmentDraftsUpdateSignalData($drafts))->toArray(),
        );

        $this->assertSame($drafts, $restored->attachmentDrafts);
        $this->assertSame(['attachmentDrafts' => $drafts], $restored->toArray());
    }

    /**
     * Outbound moderation state updates preserve null and non-null states.
     */
    public function testOutboundModerationStateUpdateRoundtrip(): void
    {
        $state = [
            'requestId' => 'request-1',
            'phase' => ChatUserState::OUTBOUND_MODERATION_PHASE_CHECKING,
            'text' => 'hello',
            'attachments' => [],
            'reason' => null,
            'updatedAt' => 1710000000,
        ];

        $restored = OutboundModerationStateUpdateSignalData::fromArray(
            (new OutboundModerationStateUpdateSignalData($state))->toArray(),
        );

        $this->assertSame($state, $restored->state);
        $this->assertSame(
            ['outboundModerationState' => $state],
            $restored->toArray(),
        );

        $cleared = OutboundModerationStateUpdateSignalData::fromArray(
            (new OutboundModerationStateUpdateSignalData(null))->toArray(),
        );
        $this->assertNull($cleared->state);
        $this->assertSame(['outboundModerationState' => null], $cleared->toArray());
    }

    /**
     * File upload completion carries the created attachment draft row.
     */
    public function testFileUploadCompleteRoundtripPreservesAttachmentDraft(): void
    {
        $draft = [
            'draftId' => 'upload-1',
            'filename' => 'photo.jpg',
            'mimeType' => 'image/jpeg',
            'size' => 2048,
            'uploadedAt' => 1710000000,
        ];

        $restored = FileUploadCompleteSignalData::fromArray(
            (new FileUploadCompleteSignalData('upload-1', 'photo.jpg', $draft))->toArray(),
        );

        $this->assertSame('upload-1', $restored->uploadId);
        $this->assertSame('photo.jpg', $restored->filename);
        $this->assertSame($draft, $restored->attachmentDraft);
        $this->assertSame(
            [
                'uploadId' => 'upload-1',
                'filename' => 'photo.jpg',
                'attachmentDraft' => $draft,
            ],
            $restored->toArray(),
        );
    }
}
