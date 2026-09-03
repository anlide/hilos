<?php

declare(strict_types=1);

namespace Hilos\Database\Pages;

/**
 * PageCatalogConstants - Key names of the page catalog and of the identity it puts on the wire.
 *
 * Two groups that deliberately stay apart. `CATALOG_ENTRY_*` and `SECTION_*` name the fields of
 * the catalog itself - the shape a project writes when it declares its own pages. `WIRE_*` names
 * the keys the identity travels under inside the `data` section of a page_response. Values
 * coincide where the field crosses unchanged, and that coincidence is not a link: renaming a wire
 * key is a frontend contract change, renaming a catalog field is not.
 *
 * @see HilosPageCatalog
 * @see PageCatalogResolver
 */
final class PageCatalogConstants
{
    /** @var string Catalog entry: parent page key in the admin tree; absent on the tree root */
    public const string CATALOG_ENTRY_PARENT = 'parent';

    /** @var string Catalog entry: heading of the page, which is also its breadcrumb caption */
    public const string CATALOG_ENTRY_LABEL = 'label';

    /** @var string Catalog entry: one-line description under the heading and on the dashboard card */
    public const string CATALOG_ENTRY_LEAD = 'lead';

    /** @var string Catalog entry: Bootstrap icon name (`bi-*`) for the dashboard card */
    public const string CATALOG_ENTRY_ICON = 'icon';

    /** @var string Dashboard section: group heading */
    public const string SECTION_TITLE = 'title';

    /** @var string Dashboard section: one-line group description */
    public const string SECTION_DESCRIPTION = 'description';

    /** @var string Dashboard section: ordered page keys shown as cards in the group */
    public const string SECTION_ITEMS = 'items';

    /** @var string Wire: the subscribing page's own heading */
    public const string WIRE_PAGE_LABEL = 'pageLabel';

    /** @var string Wire: the subscribing page's own lead */
    public const string WIRE_PAGE_LEAD = 'pageLead';

    /** @var string Wire: breadcrumb chain, root first, ending with the subscribing page */
    public const string WIRE_PAGE_BREADCRUMB = 'pageBreadcrumb';

    /** @var string Wire: the subscribing page's own children, in catalog order; empty on a leaf */
    public const string WIRE_PAGE_CHILDREN = 'pageChildren';

    /** @var string Wire: dashboard sections, sent to the dashboard page alone */
    public const string WIRE_DASHBOARD_SECTIONS = 'dashboardSections';

    /** @var string Wire: page key of one breadcrumb link; the frontend builds the URL from it */
    public const string WIRE_CRUMB_PAGE = 'page';

    /** @var string Wire: caption of one breadcrumb link */
    public const string WIRE_CRUMB_LABEL = 'label';

    /** @var string Wire: page key of one dashboard card; the frontend builds the URL from it */
    public const string WIRE_ITEM_PAGE = 'page';

    /** @var string Wire: page key of one child card; the frontend builds the URL from it */
    public const string WIRE_CHILD_PAGE = 'page';
}
