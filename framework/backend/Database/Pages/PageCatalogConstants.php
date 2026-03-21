<?php

declare(strict_types=1);

namespace Hilos\Database\Pages;

/**
 * PageCatalogConstants - Constants for page catalog structure.
 *
 * Defines shared field names for backend/frontend page catalog exchange.
 */
final class PageCatalogConstants
{
    // Catalog entry keys
    public const string CATALOG_ENTRY_PARENT_ID = 'parent_id';
    public const string CATALOG_ENTRY_LABEL = 'label';
    public const string CATALOG_ENTRY_DYNAMIC_PARAM = 'dynamic_param';
    public const string CATALOG_ENTRY_HIDE_BREADCRUMB = 'hide_breadcrumb';

    // Stub page keys (example keys for PageCatalogStub)
    public const string STUB_KEY_MAIN = 'stub_main';
    public const string STUB_KEY_CHILD = 'stub_child';
}
