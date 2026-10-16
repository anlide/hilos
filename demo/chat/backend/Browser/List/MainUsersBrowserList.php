<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserSource;
use Hilos\Database\Object\Item\User;
use Hilos\Database\Object\Item\UserMerge;
use Hilos\Database\Object\Item\UserPhoto;
use Hilos\Database\View\Item\UserPhoto as ViewUserPhoto;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * Browser list source for users visible on the main chat page.
 *
 * Beside the person and their presence, an item carries the person's photo - the address of its
 * public avatar variant - and the mark of an account folded into another one (HIL-1300). Each is
 * a slot of its own, filled only for the people who have one: `userPhotos` for a person with a
 * photo, `userMerges` for a merged account. They are separate sources rather than fields of the
 * person's slot because an item is rebuilt only by a change of a source it declares: a photo set
 * or removed, or an account merged, writes the photo or the merge table and never the person's
 * row, so a field computed on the person would keep its old value until the person changed for
 * some other reason.
 */
final class MainUsersBrowserList
{
    public const string LIST = ChatBrowserList::MAIN_USERS;

    public const array BROWSER = [
        BrowserListConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
            ChatBrowserSource::RT_CONNECTIONS,
            ChatBrowserSource::DB_USER_PHOTOS,
            ChatBrowserSource::DB_USER_MERGES,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserListFieldKey::ITEM_KEY => User::id,
                BrowserListFieldKey::FIELDS => [
                    User::id,
                    User::name,
                    User::lastActivity,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => Connection::userId,
                BrowserListFieldKey::FIELDS => [
                    Connection::userId,
                ],
                BrowserListFieldKey::COMPUTED => [
                    HilosUserPresenceSummary::presence,
                    HilosUserPresenceSummary::onlineSessionCount,
                ],
                BrowserListFieldKey::TRIGGERS => [
                    Connection::userId,
                    Connection::connectedAt,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_USER_PHOTOS,
                BrowserListFieldKey::ITEM_KEY => UserPhoto::userId,
                BrowserListFieldKey::FIELDS => [
                    UserPhoto::userId,
                    ViewUserPhoto::photo,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_USER_MERGES,
                BrowserListFieldKey::ITEM_KEY => UserMerge::userId,
                BrowserListFieldKey::FIELDS => [
                    UserMerge::userId,
                ],
            ],
        ],
    ];
}
