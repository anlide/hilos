<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Page\DTO\AttachmentDraftDeleteActionDTO;
use Demo\Chat\Core\Router\DTO\AttachmentDraftSignalData;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
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
     * Self connection updates preserve session-local state through IPC roundtrip.
     */
    public function testSelfConnectionUpdateRoundtrip(): void
    {
        $selfConnection = [
            'userId' => 7,
            'connectedAt' => 1710000000,
            'messageRateLimitSecondsRemaining' => 6,
            'outboundModerationState' => [
                'phase' => Connection::OUTBOUND_MODERATION_PHASE_CHECKING,
                'text' => 'hello',
                'reason' => null,
                'updatedAt' => 1710000001,
            ],
            'fileUploadState' => [
                'phase' => Connection::FILE_UPLOAD_PHASE_READY,
                'clientUploadId' => 'client-upload-1',
                'errorCode' => null,
                'errorMessage' => null,
            ],
            'fileUploadProgress' => [
                'filename' => 'photo.jpg',
                'uploadedBytes' => 512,
                'totalBytes' => 1024,
            ],
        ];

        $restored = SelfConnectionSignalData::fromArray(
            (new SelfConnectionSignalData($selfConnection))->toArray(),
        );

        $this->assertSame($selfConnection, $restored->selfConnection);
        $this->assertSame(['selfConnection' => $selfConnection], $restored->toArray());
    }

    /**
     * Self connection updates can carry framework frontend state changes without a selfConnection payload.
     */
    public function testSelfConnectionUpdateCarriesFrontendChanges(): void
    {
        $frontend = new FrontendChangesDTO(
            full: ['attachmentDrafts' => [[
                'draftId' => 'draft-1',
                'filename' => 'report.pdf',
                'mimeType' => 'application/pdf',
                'size' => 1234,
                'uploadedAt' => 1710000000,
            ]]],
            replaceFullKeys: ['attachmentDrafts'],
        );

        $restored = SelfConnectionSignalData::fromArray(
            SelfConnectionSignalData::fromFrontendChanges($frontend)->toArray(),
        );

        $this->assertSame($frontend->toArray(), $restored->toArray()['frontend'] ?? null);
    }

    /**
     * Single draft payload preserves the browser-facing field names.
     */
    public function testAttachmentDraftSignalDataRoundtrip(): void
    {
        $draft = [
            'draftId' => 'draft-1',
            'filename' => 'report.pdf',
            'mimeType' => 'application/pdf',
            'size' => 1234,
            'uploadedAt' => 1710000000,
        ];

        $restored = AttachmentDraftSignalData::fromArray(
            (new AttachmentDraftSignalData(
                draftId: 'draft-1',
                filename: 'report.pdf',
                mimeType: 'application/pdf',
                size: 1234,
                uploadedAt: 1710000000,
            ))->toArray(),
        );

        $this->assertSame($draft, $restored->toArray());
    }
}
