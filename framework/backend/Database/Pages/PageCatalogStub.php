<?php

declare(strict_types=1);

namespace Hilos\Database\Pages;

/**
 * PageCatalogStub - Stub-example of a project page catalog.
 *
 * The framework default: a project that owns no admin pages of its own declares nothing, and
 * both halves come back empty, leaving the framework catalog as the whole of it. A project that
 * does own admin pages writes its own {@see PageCatalogProviderInterface} implementation rather
 * than deriving from this one - there is nothing here to inherit, and the two halves it would
 * inherit are empty by definition.
 *
 * @see PageCatalogConstants
 */
final class PageCatalogStub implements PageCatalogProviderInterface
{
    /**
     * @return array<string, array{label: string, lead: string, parent?: string, icon?: string}> No project pages
     */
    public static function pages(): array
    {
        return [];
    }

    /**
     * @return list<array{title: string, description: string, items: list<string>}> No project sections
     */
    public static function dashboardSections(): array
    {
        return [];
    }
}
