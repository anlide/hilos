<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Data;

use Demo\Chat\Browser\ChatBrowserData;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Demo\Chat\Pages\UserPage;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Database\Object\Item\UserMerge;
use Hilos\Database\View\Collection\UserMerges;
use Hilos\Tables\Users\HilosUserTableRow;

/**
 * Browser data source for the chat user detail page: whether the account was folded into
 * another one, and where that chain ends (HIL-1292).
 *
 * The row is the person's own tombstone in the framework merge table, so an account that was
 * never merged has no data here at all - which is how the page tells the two apart. Where the
 * account went is computed off that row: the live end of the merge chain
 * ({@see UserMerges::liveSurvivorOf()}) and that account's name, read the same way the admin
 * card reads them. The name is computed rather than joined: a joined row is held to the row key
 * of the person shown, and the survivor is somebody else. The page stays on the merged account's
 * own address ({@see UserPage}), and what it says is read when the row is built - a chain that
 * grows past the survivor, or a survivor renamed, is seen on the page's next open; this source
 * watches the tombstone of the one account it shows, and nothing else moves it.
 */
final class UserMergeBrowserData
{
    public const string DATA = ChatBrowserData::USER_MERGE;

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            UserPageSubscribeParams::USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            ChatBrowserSource::DB_USER_MERGES,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => ChatBrowserSource::DB_USER_MERGES,
                BrowserDataFieldKey::ROW_KEY => UserMerge::userId,
                BrowserDataFieldKey::WHERE => [
                    UserMerge::userId => ChatBrowserRef::TABLE_USER_ID,
                ],
                BrowserDataFieldKey::FIELDS => [
                    UserMerge::userId,
                ],
                BrowserDataFieldKey::COMPUTED => [
                    HilosUserTableRow::FIELD_MERGED_INTO,
                    HilosUserTableRow::FIELD_MERGED_INTO_NAME,
                ],
                // The survivor's id is open to a viewer of the admin view mode, the name is a person's and stays hidden.
                BrowserDataFieldKey::NOT_PERSONAL => [
                    HilosUserTableRow::FIELD_MERGED_INTO,
                ],
            ],
        ],
    ];
}
