<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Consistency of the framework's own admin catalog with itself and with the page keys.
 *
 * Topology validation makes the same judgement at daemon start, over the merged catalog. This
 * pins the framework half on its own, where a typo is a compile-time-ish fact rather than a
 * project's boot failure: the table is 60 hand-written entries, and a key that names no page
 * would otherwise surface as a section that silently never gets identity.
 */
final class HilosPageCatalogTest extends TestCase
{
    /**
     * Number of entries in the catalog. Sixty of them came over from the frontend when the
     * identity moved to the backend (HIL-624); the profile tree joined in HIL-288, while six
     * routed keys still carry no entry on purpose - four public footer pages and the guardian pair - and
     * every page declared since is one more, the logging modes of HIL-762 being the first.
     *
     * It is a deliberate-addition guard rather than a count worth knowing: a page that reaches the
     * catalog is a name and a lead an administrator will read, so it arrives by somebody writing
     * it down here as well.
     */
    private const int CATALOG_ENTRIES = 66;

    /** Number of dashboard sections carried over in the same transfer. */
    private const int TRANSFERRED_SECTIONS = 5;

    public function testEveryKeyNamesADeclaredPage(): void
    {
        // Which page keys are declared, asked of the declaration itself: PHP has no plain reader
        // for the constants of a class, and a second hand-kept list would be the very drift the
        // test is here to catch.
        $declared = (new ReflectionClass(HilosPageConstants::class))->getConstants();

        foreach (array_keys(HilosPageCatalog::CATALOG) as $page) {
            self::assertContains($page, $declared, "Catalog entry {$page} names no page constant");
        }
    }

    public function testEveryParentIsItselfAnEntry(): void
    {
        foreach (HilosPageCatalog::CATALOG as $page => $entry) {
            $parent = $entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null;
            if ($parent === null) {
                continue;
            }

            self::assertArrayHasKey(
                $parent,
                HilosPageCatalog::CATALOG,
                "Catalog entry {$page} names parent {$parent}, which has no entry",
            );
        }
    }

    /**
     * The admin dashboard and the current-user profile are separate catalog roots.
     */
    public function testTheDashboardIsTheOnlyRoot(): void
    {
        $roots = [];
        foreach (HilosPageCatalog::CATALOG as $page => $entry) {
            if (!isset($entry[PageCatalogConstants::CATALOG_ENTRY_PARENT])) {
                $roots[] = $page;
            }
        }

        self::assertSame([HilosPageConstants::HILOS_DASHBOARD, HilosPageConstants::HILOS_PROFILE], $roots);
    }

    public function testEverySectionItemIsAnEntry(): void
    {
        foreach (HilosPageCatalog::DASHBOARD_SECTIONS as $section) {
            foreach ($section[PageCatalogConstants::SECTION_ITEMS] as $page) {
                self::assertArrayHasKey(
                    $page,
                    HilosPageCatalog::CATALOG,
                    "Dashboard section {$section[PageCatalogConstants::SECTION_TITLE]} lists {$page}, which has no entry",
                );
            }
        }
    }

    public function testTheTransferIsComplete(): void
    {
        self::assertCount(self::CATALOG_ENTRIES, HilosPageCatalog::CATALOG);
        self::assertCount(self::TRANSFERRED_SECTIONS, HilosPageCatalog::DASHBOARD_SECTIONS);
    }
}
