<?php

declare(strict_types=1);

namespace Hilos\Database\Pages;

use Hilos\Hilos;

/**
 * PageCatalogProviderInterface - How a project adds its own pages to the admin catalog.
 *
 * A project points {@see Hilos::getPageCatalogClass()} at an implementation and declares there
 * the identity of the admin pages the framework does not know about; entries under a key the
 * framework already carries rename that section rather than colliding with it.
 *
 * Deliberately not `Hilos\Core\Catalog\CatalogProviderInterface`, which the env, settings, LLM
 * and backup catalogs implement: that one hands over a single `getCatalog()` map, and the page
 * catalog has two shapes that do not fold into one - a map of page identity and an ordered list
 * of dashboard sections.
 *
 * @see PageCatalogStub
 */
interface PageCatalogProviderInterface
{
    /**
     * Returns the project's page entries, keyed by page key.
     *
     * @return array<string, array{label: string, lead: string, parent?: string, icon?: string}> Page identity per page key
     */
    public static function pages(): array;

    /**
     * Returns the project's dashboard sections, in display order.
     *
     * @return list<array{title: string, description: string, items: list<string>}> Dashboard sections
     */
    public static function dashboardSections(): array;
}
