<?php

declare(strict_types=1);

namespace Hilos\Tables\Users;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\UserPhoto as ObjectUserPhoto;

/** The published photo of the person on the admin user card, when one exists. */
final class HilosUserPhotoBrowserTable
{
    public const string TABLE = 'userPhoto';

    public const string FIELD_PHOTO = 'photo';

    public const array BINDING = [
        BrowserParamKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
            ],
        ],
    ];

    public const array BROWSER = [
        BrowserTableConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserTableConfigKey::SOURCES => [
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::userPhotos],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => HilosDbContext::userPhotos,
                ],
                BrowserTableFieldKey::ROW_KEY => ObjectUserPhoto::userId,
                BrowserTableFieldKey::WHERE => [
                    ObjectUserPhoto::userId => [
                        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
                        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
                    ],
                ],
                BrowserTableFieldKey::FIELDS => [ObjectUserPhoto::userId, ObjectUserPhoto::fileId],
                BrowserTableFieldKey::COMPUTED => [self::FIELD_PHOTO],
            ],
        ],
    ];
}
