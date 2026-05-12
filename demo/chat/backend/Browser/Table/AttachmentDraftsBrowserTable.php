<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Core\Router\DTO\AttachmentDraftSignalData;
use Demo\Chat\Runtime\State\Item\AttachmentDraft;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;

/**
 * Browser table config for current-connection attachment drafts.
 */
final class AttachmentDraftsBrowserTable
{
    public const string TABLE = ChatBrowserTable::ATTACHMENT_DRAFTS;

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::RT_ATTACHMENT_DRAFTS,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::RT_ATTACHMENT_DRAFTS,
                BrowserFieldKey::WHERE => [
                    AttachmentDraft::acceptKey => ChatBrowserRef::TABLE_ACCEPT_KEY,
                ],
                BrowserFieldKey::FIELDS => [
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
