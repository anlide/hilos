<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Data;

use Demo\Chat\Browser\ChatBrowserData;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Database\Object\Item\UserPhoto;
use Hilos\Database\View\Item\UserPhoto as ViewUserPhoto;
use Hilos\Tables\Users\HilosUserPhotoBrowserTable;

/**
 * Browser data source for the chat user detail page: the person's photo (HIL-1300).
 *
 * The row is the person's own row of the framework photo table, so a person without a photo has no
 * data here at all - the same way {@see UserMergeBrowserData} answers for an account never merged.
 * What it carries is the address of the photo's public avatar variant ({@see ViewUserPhoto::photo}),
 * which a guest loads without a cookie. A photo set, replaced or removed writes this one row, and
 * this source watches it and nothing else. Nothing here is declared not personal: the page is not an
 * administrator's, and a face is personal.
 *
 * The page reads it as `personPhoto`, not `userPhoto`: browser lists, tables and data share one key
 * space, and `userPhoto` is the framework's admin photo card ({@see HilosUserPhotoBrowserTable::TABLE}).
 */
final class UserPhotoBrowserData
{
    public const string DATA = ChatBrowserData::USER_PHOTO;

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            UserPageSubscribeParams::USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            ChatBrowserSource::DB_USER_PHOTOS,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => ChatBrowserSource::DB_USER_PHOTOS,
                BrowserDataFieldKey::ROW_KEY => UserPhoto::userId,
                BrowserDataFieldKey::WHERE => [
                    UserPhoto::userId => ChatBrowserRef::TABLE_USER_ID,
                ],
                BrowserDataFieldKey::FIELDS => [
                    UserPhoto::userId,
                    ViewUserPhoto::photo,
                ],
            ],
        ],
    ];
}
