<?php

declare(strict_types=1);

namespace Hilos\Core\Catalog;

/**
 * Provides a static catalog declaration for catalog-backed framework accessors.
 */
interface CatalogProviderInterface
{
    /**
     * Returns a catalog keyed by its business key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getCatalog(): array;
}
