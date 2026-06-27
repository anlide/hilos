<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Core\Router\DTO\AttachmentDraftSignalData;
use Demo\Chat\Runtime\State\Item\AttachmentDraft;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;

/**
 * Browser list source for current-connection attachment drafts.
 */
final class AttachmentDraftsBrowserList
{
    public const string LIST = ChatBrowserList::ATTACHMENT_DRAFTS;

    public const array BROWSER = [
        BrowserListConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            ChatBrowserSource::RT_ATTACHMENT_DRAFTS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::RT_ATTACHMENT_DRAFTS,
                BrowserListFieldKey::ITEM_KEY => AttachmentDraft::draftId,
                BrowserListFieldKey::WHERE => [
                    AttachmentDraft::acceptKey => ChatBrowserRef::TABLE_ACCEPT_KEY,
                ],
                BrowserListFieldKey::FIELDS => [
                    AttachmentDraft::draftId => AttachmentDraftSignalData::draftId,
                    AttachmentDraft::originalFilename => AttachmentDraftSignalData::filename,
                    AttachmentDraft::mimeType => AttachmentDraftSignalData::mimeType,
                    AttachmentDraft::size => AttachmentDraftSignalData::size,
                    AttachmentDraft::uploadedAt => AttachmentDraftSignalData::uploadedAt,
                ],
            ],
        ],
    ];
}
