<?php

declare(strict_types=1);

namespace Hilos\Database\Pages;

use Hilos\Hilos;

/**
 * PageCatalogResolver - Reads the page catalog: the merge, one page's identity, its breadcrumb.
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
     * @var array<string, array<string, array{label: string, lead: string, parent?: string, icon?: string}>> Merged catalog per provider class
     */
    private static array $catalogs = [];

    /**
     * Returns the merged catalog, keyed by page key.
     *
     * @return array<string, array{label: string, lead: string, parent?: string, icon?: string}> Page identity per page key
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
     * @return array<string, array{label: string, lead: string, parent?: string, icon?: string}> Page identity per page key
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

        // Identity is the entry minus its place in the tree, which the breadcrumb reads instead.
        unset($entry[PageCatalogConstants::CATALOG_ENTRY_PARENT]);

        return $entry;
    }

    /**
     * Returns the breadcrumb chain of a page, the dashboard first and the page itself last.
     *
     * A page at the root of the tree gets a chain of one link, itself. A page the catalog does
     * not carry gets an empty chain, the same non-error as {@see self::identity()}.
     *
     * The walk up `parent` is not guarded against a broken tree, deliberately: a parent naming
     * no entry, or a cycle, is refused by topology validation before the daemon serves anything,
     * and a runtime guard here would swallow the typo the startup check exists to show.
     *
     * @param string $page Page key
     * @return list<array{page: string, label: string}> Breadcrumb links from the root down to this page
     */
    public static function breadcrumb(string $page): array
    {
        $catalog = self::catalog();
        if (!isset($catalog[$page])) {
            return [];
        }

        $crumbs = [];
        $key = $page;
        while ($key !== null) {
            $entry = $catalog[$key];
            $crumbs[] = [
                PageCatalogConstants::WIRE_CRUMB_PAGE => $key,
                PageCatalogConstants::WIRE_CRUMB_LABEL => $entry[PageCatalogConstants::CATALOG_ENTRY_LABEL],
            ];
            $key = $entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null;
        }

        return array_reverse($crumbs);
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
}
