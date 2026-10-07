<?php
declare(strict_types=1);

class HilosPageCatalog
{
    public const array CATALOG = [
        HilosPageConstants::HILOS_BUILT => [
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_HUB,
        ],
        HilosPageConstants::HILOS_LAYERED => [
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_HUB,
        ],
        HilosPageConstants::HILOS_STUB => [
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_EMPTY,
        ],
        HilosPageConstants::HILOS_I18N_LANGUAGE => [
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_LANGUAGES,
        ],
    ];
}
