<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\Object\Item\Event;
use Demo\Chat\Database\Object\Item\EventAttachment;
use Demo\Chat\Database\Object\Item\EventMessage;
use Demo\Chat\Database\Object\Item\EventUserRegistration;
use Demo\Chat\Database\Object\Item\EventUserRename;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;

/**
 * Browser list source for the main chat event stream.
 */
final class MainEventsBrowserList
{
    public const string LIST = ChatBrowserList::MAIN_EVENTS;

    public const array BROWSER = [
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::DB_EVENTS,
            ChatBrowserSource::DB_EVENT_MESSAGES,
            ChatBrowserSource::DB_EVENT_USER_REGISTRATIONS,
            ChatBrowserSource::DB_EVENT_USER_RENAMES,
            ChatBrowserSource::DB_EVENT_ATTACHMENTS,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_EVENTS,
                BrowserFieldKey::ROW_KEY => Event::id,
                BrowserFieldKey::FIELDS => [
                    Event::id,
                    Event::type,
                    Event::timestamp,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_EVENT_MESSAGES,
                BrowserFieldKey::ROW_KEY => EventMessage::eventId,
                BrowserFieldKey::FIELDS => [
                    EventMessage::eventId,
                    EventMessage::authorUserId,
                    EventMessage::authorBotId,
                    EventMessage::message,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_EVENT_USER_REGISTRATIONS,
                BrowserFieldKey::ROW_KEY => EventUserRegistration::eventId,
                BrowserFieldKey::FIELDS => [
                    EventUserRegistration::eventId,
                    EventUserRegistration::targetUserId,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_EVENT_USER_RENAMES,
                BrowserFieldKey::ROW_KEY => EventUserRename::eventId,
                BrowserFieldKey::FIELDS => [
                    EventUserRename::eventId,
                    EventUserRename::targetUserId,
                    EventUserRename::actorUserId,
                    EventUserRename::oldName,
                    EventUserRename::newName,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_EVENT_ATTACHMENTS,
                BrowserFieldKey::ROW_KEY => EventAttachment::eventId,
                BrowserFieldKey::MANY => true,
                BrowserFieldKey::FIELDS => [
                    EventAttachment::id,
                    EventAttachment::eventId,
                    EventAttachment::filename,
                    EventAttachment::mimeType,
                ],
            ],
        ],
    ];
}
