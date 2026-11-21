<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser\List;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;

/**
 * The categories of the home page: every shop category with its name, description, address
 * slug, position in the catalog and the address of its tile picture. A list keeps creation,
 * renaming, moving and deletion live; the browser orders the tiles by position.
 */
final class MainCategoriesBrowserList
{
    public const string LIST = EcommerceShopBrowserList::MAIN_CATEGORIES;

    /** @var string Address of the category picture's tile copy */
    public const string TILE_PICTURE = 'tilePicture';

    public const array BROWSER = [
        BrowserListConfigKey::SOURCES => [
            EcommerceShopBrowserSource::DB_CATEGORIES,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => EcommerceShopBrowserSource::DB_CATEGORIES,
                BrowserListFieldKey::ITEM_KEY => ObjectCategory::id,
                BrowserListFieldKey::FIELDS => [
                    ObjectCategory::id,
                    ObjectCategory::name,
                    ObjectCategory::description,
                    ObjectCategory::slug,
                    ObjectCategory::position,
                ],
                BrowserListFieldKey::COMPUTED => [
                    self::TILE_PICTURE,
                ],
                BrowserListFieldKey::NOT_PERSONAL => [
                    self::TILE_PICTURE,
                ],
            ],
        ],
    ];
}
