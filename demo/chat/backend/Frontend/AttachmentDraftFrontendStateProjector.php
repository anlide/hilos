<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend;

use Demo\Chat\Core\Router\DTO\AttachmentDraftSignalData;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Core\Router\DTO\FrontendChangesDTO;

/**
 * Builds connection-local attachment draft frontend state projections.
 */
final class AttachmentDraftFrontendStateProjector
{
    /**
     * Builds an authoritative full draft list for one connection.
     */
    public static function fullForConnection(Connection $connection): FrontendChangesDTO
    {
        return new FrontendChangesDTO(
            full: [
                FrontendStateCollectionKey::ATTACHMENT_DRAFTS =>
                    AttachmentDraftSignalData::listFromDrafts($connection->attachmentDrafts),
            ],
            replaceFullKeys: [FrontendStateCollectionKey::ATTACHMENT_DRAFTS],
        );
    }

    /**
     * Appends a full draft list to an existing frontend state snapshot.
     */
    public static function appendFullForConnection(
        FrontendChangesDTO $frontend,
        Connection $connection,
    ): FrontendChangesDTO {
        $payload = $frontend->toArray();
        $payload['full'][FrontendStateCollectionKey::ATTACHMENT_DRAFTS] =
            AttachmentDraftSignalData::listFromDrafts($connection->attachmentDrafts);
        $payload['replaceFull'][] = FrontendStateCollectionKey::ATTACHMENT_DRAFTS;

        return FrontendChangesDTO::fromArray($payload);
    }
}
