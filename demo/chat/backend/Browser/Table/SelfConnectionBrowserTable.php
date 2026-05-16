<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Runtime\State\Item\ChatUserState;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;

/**
 * Browser table config for current WebSocket connection state.
 */
final class SelfConnectionBrowserTable
{
    public const string TABLE = ChatBrowserTable::SELF_CONNECTION;

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
            ChatBrowserSource::RT_CONNECTIONS,
            ChatBrowserSource::RT_USER_STATES,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserFieldKey::ROW_KEY => Connection::userId,
                BrowserFieldKey::WHERE => [
                    Connection::acceptKey => ChatBrowserRef::TABLE_ACCEPT_KEY,
                ],
                BrowserFieldKey::FIELDS => [
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
                BrowserFieldKey::COMPUTED => [
                    SelfConnectionSignalData::outboundModerationState,
                    SelfConnectionSignalData::fileUploadState,
                    SelfConnectionSignalData::fileUploadProgress,
                ],
                BrowserFieldKey::TRIGGERS => [
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
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserFieldKey::ROW_KEY => User::id,
                BrowserFieldKey::VIA => [
                    User::id => Connection::userId,
                ],
                BrowserFieldKey::FIELDS => [
                    User::id,
                    User::name,
                ],
                BrowserFieldKey::TRIGGERS => [
                    User::name,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::RT_USER_STATES,
                BrowserFieldKey::ROW_KEY => ChatUserState::userId,
                BrowserFieldKey::VIA => [
                    ChatUserState::userId => Connection::userId,
                ],
                BrowserFieldKey::FIELDS => [
                    ChatUserState::lastOutboundSubmittedAt,
                ],
                BrowserFieldKey::COMPUTED => [
                    SelfConnectionSignalData::messageRateLimitSecondsRemaining,
                ],
                BrowserFieldKey::TRIGGERS => [
                    ChatUserState::lastOutboundSubmittedAt,
                ],
            ],
        ],
    ];
}
