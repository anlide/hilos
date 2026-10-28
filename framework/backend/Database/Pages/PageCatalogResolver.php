<?php

declare(strict_types=1);

namespace Hilos\Database\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Pages\DTO\PageHead;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * PageCatalogResolver - Reads the page catalog: the merge, a page's identity, breadcrumb and children.
 *
 * Every read of admin page identity goes through here, so the merge rule is stated once: the
 * framework catalog first, the project's entries laid over it. An entry under a key the framework
 * already carries wins, which is how a project renames a section into the language of its
 * product; an entry under a new key adds a page the framework does not know.
 *
 * @see HilosPageCatalog
 * @see PageCatalogProviderInterface
 */
final class PageCatalogResolver
{
    /**
     * Merged catalog, kept per provider class.
     *
     * A process runs one project facade and therefore fills one entry: the merge is the same
     * answer for the whole life of the process, since both halves are constants. Keyed by the
     * provider rather than held flat so that a second facade - which only a test binds - gets its
     * own answer instead of the first one's.
     *
     * @var array<string, array<string, array{
     *     label: string, lead: string, parent?: string, icon?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }>> Immutable catalog metadata per provider class
     */
    private static array $catalogs = [];

    /**
     * Returns the merged catalog, keyed by page key.
     *
     * @return array<string, array{
     *     label: string, lead: string, parent?: string, icon?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }> Catalog metadata per page key
     */
    public static function catalog(): array
    {
        return self::catalogOf(Hilos::getPageCatalogClass());
    }

    /**
     * Returns the merged catalog of a named provider, keyed by page key.
     *
     * The form topology validation reads: it judges a facade class before that class is the
     * running one, so the provider arrives by name instead of through the facade.
     *
     * @param class-string<PageCatalogProviderInterface> $provider Page catalog provider class
     * @return array<string, array{
     *     label: string, lead: string, parent?: string, icon?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }> Catalog metadata per page key
     */
    public static function catalogOf(string $provider): array
    {
        return self::$catalogs[$provider] ??= array_merge(HilosPageCatalog::CATALOG, $provider::pages());
    }

    /**
     * Returns one page's own identity - its label, its lead, and its icon where it has one.
     *
     * Null for a page the catalog does not carry, which is not an error: the public footer pages
     * and any project page outside the admin tree have no entry, and their subscription simply
     * answers without identity.
     *
     * @param string $page Page key
     * @return ?array{label: string, lead: string, icon?: string} Page identity, or null when the catalog carries no entry
     */
    public static function identity(string $page): ?array
    {
        $entry = self::catalog()[$page] ?? null;
        if ($entry === null) {
            return null;
        }

        $identity = [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => $entry[PageCatalogConstants::CATALOG_ENTRY_LABEL],
            PageCatalogConstants::CATALOG_ENTRY_LEAD => $entry[PageCatalogConstants::CATALOG_ENTRY_LEAD],
        ];
        if (isset($entry[PageCatalogConstants::CATALOG_ENTRY_ICON])) {
            $identity[PageCatalogConstants::CATALOG_ENTRY_ICON] = $entry[PageCatalogConstants::CATALOG_ENTRY_ICON];
        }

        return $identity;
    }

    /**
     * @param string $page Page key
     * @return ?string Nearest entity or top-level admin section; null outside the admin branch or on the dashboard
     */
    public static function rowOwner(string $page): ?string
    {
        return self::rowOwnerOf($page, Hilos::getPageCatalogClass());
    }

    /**
     * Resolves ownership before a facade is bound. Adoption never changes entity boundaries.
     *
     * @param string $page Page key
     * @param class-string<PageCatalogProviderInterface> $provider Page catalog provider class
     * @return ?string Row owner, or null outside the admin branch, on the dashboard or for an unknown page
     */
    public static function rowOwnerOf(string $page, string $provider): ?string
    {
        $catalog = self::catalogOf($provider);
        if (!isset($catalog[$page]) || $page === HilosPageConstants::HILOS_DASHBOARD) {
            return null;
        }

        $entity = null;
        $key = $page;
        while ($key !== null) {
            $entry = $catalog[$key];
            if ($entity === null && ($entry[PageCatalogConstants::CATALOG_ENTRY_ENTITY] ?? false)) {
                $entity = $key;
            }

            $parent = $entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null;
            if ($parent === HilosPageConstants::HILOS_DASHBOARD) {
                return $entity ?? $key;
            }
            $key = $parent;
        }

        return null;
    }

    /**
     * Adopted rows end at the owner and use breadcrumb captions, omitting explicit empty captions.
     * Other pages keep their legacy label chain through the subscribing page itself.
     *
     * Startup topology validation refuses missing parents and cycles; this walk does not hide them.
     *
     * @param string $page Page key
     * @return list<array{page: string, label: string}> Breadcrumb links from the root, or empty for an unknown page
     */
    public static function breadcrumb(string $page): array
    {
        $catalog = self::catalog();
        if (!isset($catalog[$page])) {
            return [];
        }

        $owner = self::rowOwner($page);
        $adopted = $owner !== null && ($catalog[$owner][PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD] ?? false);
        $crumbs = [];
        $key = $adopted ? $owner : $page;
        while ($key !== null) {
            $entry = $catalog[$key];
            $label = $adopted
                ? ($entry[PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB] ?? $entry[PageCatalogConstants::CATALOG_ENTRY_LABEL])
                : $entry[PageCatalogConstants::CATALOG_ENTRY_LABEL];
            if (!$adopted || $label !== '') {
                $crumbs[] = [
                    PageCatalogConstants::WIRE_CRUMB_PAGE => $key,
                    PageCatalogConstants::WIRE_CRUMB_LABEL => $label,
                ];
            }
            $key = $entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null;
        }

        return array_reverse($crumbs);
    }

    /**
     * Builds a fresh head for this subscription without retaining params or applicability results.
     *
     * @param string $page Subscribing page key
     * @param PageRouteParams $ownerParams Current subscription params containing the owner's coordinates
     * @return ?PageHead Shared head, or null when this page has no adopted owner
     * @throws HilosException When a page cannot read its applicability metadata
     */
    public static function head(string $page, PageRouteParams $ownerParams): ?PageHead
    {
        $owner = self::rowOwner($page);
        if ($owner === null || !(self::catalog()[$owner][PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD] ?? false)) {
            return null;
        }

        return new PageHead(
            pageHeadOwner: [PageCatalogConstants::WIRE_OWNER_PAGE => $owner] + self::identity($owner),
            pageBreadcrumb: self::breadcrumb($page),
            pageTabs: self::pageTabs($owner, $ownerParams, true),
        );
    }

    /**
     * Returns the cards of a page's own subsections, in the order the catalog declares them.
     *
     * A leaf gets an empty list, and so does a page the catalog does not carry - the same
     * non-error as {@see self::identity()}. Each card carries its page key beside the identity
     * of that page, the shape a dashboard card already travels in.
     *
     * The lookup is not guarded against a broken tree for the reason {@see self::breadcrumb()}
     * states: a parent naming no entry is refused by topology validation at startup.
     *
     * @param string $page Page key
     * @return list<array{page: string, label: string, lead: string, icon?: string}> Cards of this page's children in catalog order
     */
    public static function children(string $page): array
    {
        $children = [];
        foreach (self::catalog() as $key => $entry) {
            if (($entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null) !== $page) {
                continue;
            }

            $children[] = [PageCatalogConstants::WIRE_CHILD_PAGE => $key] + self::identity($key);
        }

        return $children;
    }

    /**
     * Returns the dashboard sections in display order, the framework's own first.
     *
     * A project's sections are appended rather than merged by title: the project is a guest in
     * the framework's dashboard, and appending is the one order that needs no rule to read.
     *
     * @return list<array{title: string, description: string, items: list<string>}> Dashboard sections in display order
     */
    public static function dashboardSections(): array
    {
        return self::dashboardSectionsOf(Hilos::getPageCatalogClass());
    }

    /**
     * Returns the dashboard sections of a named provider, the framework's own first.
     *
     * The twin of {@see self::catalogOf()}, and there for the same reader.
     *
     * @param class-string<PageCatalogProviderInterface> $provider Page catalog provider class
     * @return list<array{title: string, description: string, items: list<string>}> Dashboard sections in display order
     */
    public static function dashboardSectionsOf(string $provider): array
    {
        return array_merge(HilosPageCatalog::DASHBOARD_SECTIONS, $provider::dashboardSections());
    }

    /**
     * An absent or inapplicable candidate removes its whole branch; entities begin another row.
     *
     * @param string $page Candidate page key
     * @param PageRouteParams $ownerParams Current subscription params containing the owner's coordinates
     * @param bool $owner Whether this candidate is the row's owner
     * @return list<array{page: string, label: string}> Tab wire entries in depth-first catalog order
     * @throws HilosException When a page cannot read its applicability metadata
     */
    private static function pageTabs(string $page, PageRouteParams $ownerParams, bool $owner = false): array
    {
        if (!Hilos::$sr->servesPage($page)) {
            return [];
        }

        $pageClass = Hilos::appClass()::PAGES[$page] ?? null;
        if ($pageClass === null || !$pageClass::isTabApplicable($ownerParams)) {
            return [];
        }

        $catalog = self::catalog();
        $tabs = [[
            PageCatalogConstants::WIRE_TAB_PAGE => $page,
            PageCatalogConstants::WIRE_TAB_LABEL => $owner
                ? HilosPageCatalog::OVERVIEW_LABEL
                : ($catalog[$page][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB]
                    ?? $catalog[$page][PageCatalogConstants::CATALOG_ENTRY_LABEL]),
        ]];
        foreach ($catalog as $key => $entry) {
            if (($entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null) !== $page
                || ($entry[PageCatalogConstants::CATALOG_ENTRY_ENTITY] ?? false)) {
                continue;
            }

            array_push($tabs, ...self::pageTabs($key, $ownerParams));
        }

        return $tabs;
    }
}
