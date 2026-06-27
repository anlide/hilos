<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Data;

use Demo\Chat\Browser\ChatBrowserData;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Runtime\State\Item\ChatUserState;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;

/**
 * Browser data source for current WebSocket connection state.
 */
final class SelfConnectionBrowserData
{
    public const string DATA = ChatBrowserData::SELF_CONNECTION;

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
            ChatBrowserSource::RT_CONNECTIONS,
            ChatBrowserSource::RT_USER_STATES,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserDataFieldKey::ROW_KEY => Connection::userId,
                BrowserDataFieldKey::WHERE => [
                    Connection::acceptKey => ChatBrowserRef::TABLE_ACCEPT_KEY,
                ],
                BrowserDataFieldKey::FIELDS => [
                    Connection::userId => SelfConnectionSignalData::userId,
                    Connection::connectedAt => SelfConnectionSignalData::connectedAt,
                    Connection::outboundModerationPhase,
                    Connection::outboundModerationMessage,
                    Connection::outboundModerationReason,
                    Connection::fileUploadPhase,
                    Connection::fileUploadClientUploadId,
                    Connection::fileUploadErrorCode,
                    Connection::fileUploadErrorMessage,
                    Connection::fileProgressFilename,
                    Connection::fileProgressUploadedBytes,
                    Connection::fileProgressTotalBytes,
                ],
                BrowserDataFieldKey::COMPUTED => [
                    SelfConnectionSignalData::outboundModerationState,
                    SelfConnectionSignalData::fileUploadState,
                    SelfConnectionSignalData::fileUploadProgress,
                ],
                BrowserDataFieldKey::TRIGGERS => [
                    Connection::userId,
                    Connection::connectedAt,
                    Connection::outboundModerationPhase,
                    Connection::outboundModerationMessage,
                    Connection::outboundModerationReason,
                    Connection::outboundModerationUpdatedAt,
                    Connection::fileUploadPhase,
                    Connection::fileUploadClientUploadId,
                    Connection::fileUploadErrorCode,
                    Connection::fileUploadErrorMessage,
                    Connection::fileProgressFilename,
                    Connection::fileProgressTotalBytes,
                    Connection::uploadProgressLastSentAt,
                ],
            ],
            [
                BrowserDataFieldKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserDataFieldKey::ROW_KEY => User::id,
                BrowserDataFieldKey::VIA => [
                    User::id => Connection::userId,
                ],
                BrowserDataFieldKey::FIELDS => [
                    User::id,
                    User::name,
                ],
                BrowserDataFieldKey::TRIGGERS => [
                    User::name,
                ],
            ],
            [
                BrowserDataFieldKey::SOURCE => ChatBrowserSource::RT_USER_STATES,
                BrowserDataFieldKey::ROW_KEY => ChatUserState::userId,
                BrowserDataFieldKey::VIA => [
                    ChatUserState::userId => Connection::userId,
                ],
                BrowserDataFieldKey::FIELDS => [
                    ChatUserState::lastOutboundSubmittedAt,
                ],
                BrowserDataFieldKey::COMPUTED => [
                    SelfConnectionSignalData::messageRateLimitSecondsRemaining,
                ],
                BrowserDataFieldKey::TRIGGERS => [
                    ChatUserState::lastOutboundSubmittedAt,
                ],
            ],
        ],
    ];
}
