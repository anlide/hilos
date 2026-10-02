<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\Object\Item\Event;
use Demo\Chat\Database\Object\Item\EventAttachment;
use Demo\Chat\Database\Object\Item\EventMessage;
use Demo\Chat\Database\Object\Item\EventUserRegistration;
use Demo\Chat\Database\Object\Item\UserRename;
use Demo\Chat\Database\View\Item\EventAttachment as DbEventAttachment;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Database\Object\Item\File;

/**
 * Browser list source for the main chat event stream.
 */
final class MainEventsBrowserList
{
    public const string LIST = ChatBrowserList::MAIN_EVENTS;

    public const array BROWSER = [
        BrowserListConfigKey::SOURCES => [
            ChatBrowserSource::DB_EVENTS,
            ChatBrowserSource::DB_EVENT_MESSAGES,
            ChatBrowserSource::DB_EVENT_USER_REGISTRATIONS,
            ChatBrowserSource::DB_USER_RENAMES,
            ChatBrowserSource::DB_EVENT_ATTACHMENTS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_EVENTS,
                BrowserListFieldKey::ITEM_KEY => Event::id,
                BrowserListFieldKey::FIELDS => [
                    Event::id,
                    Event::type,
                    Event::timestamp,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_EVENT_MESSAGES,
                BrowserListFieldKey::ITEM_KEY => EventMessage::eventId,
                BrowserListFieldKey::FIELDS => [
                    EventMessage::eventId,
                    EventMessage::authorUserId,
                    EventMessage::authorBotId,
                    EventMessage::message,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_EVENT_USER_REGISTRATIONS,
                BrowserListFieldKey::ITEM_KEY => EventUserRegistration::eventId,
                BrowserListFieldKey::FIELDS => [
                    EventUserRegistration::eventId,
                    EventUserRegistration::targetUserId,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_USER_RENAMES,
                BrowserListFieldKey::ITEM_KEY => UserRename::eventId,
                BrowserListFieldKey::FIELDS => [
                    UserRename::eventId,
                    UserRename::userId,
                    UserRename::renamedByUserId,
                    UserRename::oldName,
                    UserRename::newName,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_EVENT_ATTACHMENTS,
                BrowserListFieldKey::ITEM_KEY => EventAttachment::eventId,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::FIELDS => [
                    EventAttachment::id,
                    EventAttachment::eventId,
                    EventAttachment::fileId,
                    File::filename,
                    File::mimeType,
                    DbEventAttachment::url,
                    DbEventAttachment::thumbUrl,
                ],
            ],
        ],
    ];
}
