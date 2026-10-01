<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Page\AbstractPage;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\Pages\AbstractHilosProfileAgreementsHistoryPage;
use Hilos\Pages\AbstractHilosProfileAgreementsPage;
use Hilos\Pages\AbstractHilosProfileDataPage;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pins the EXACT set of exits a freeze leaves open in the framework (HIL-945).
 *
 * A freeze takes away using the product and nothing else, and what it leaves is declared where
 * each exit lives - a page open while frozen, an action its owner lists. The dangerous change is a
 * quiet one: a page or an action joining the exits closes nothing visibly, it just hands the
 * product back to a person who has not accepted the terms. So the census is exact, not "at least".
 */
final class FrozenExitRegistryTest extends TestCase
{
    /** The pages open while frozen: the person's data, their agreements and the agreements' history. */
    private const array OPEN_WHILE_FROZEN_PAGES = [
        AbstractHilosProfileAgreementsHistoryPage::class,
        AbstractHilosProfileAgreementsPage::class,
        AbstractHilosProfileDataPage::class,
    ];

    /** Actions each framework owner lets a frozen person run, keyed by the declaring class in name order. */
    private const array FROZEN_EXIT_ACTIONS = [
        AbstractUsersLibraryAgent::class => [
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT,
            HilosSignalConstants::HILOS_LEGAL_ACCEPT,
        ],
        AbstractDataExportAgent::class => [HilosSignalConstants::HILOS_DATA_EXPORT_ORDER],
    ];

    public function testThePagesOpenWhileFrozenAreExactlyTheDeclaredExits(): void
    {
        $open = [];
        foreach ($this->frameworkClasses('Pages', AbstractPage::class) as $class) {
            if ($class::OPEN_WHILE_FROZEN) {
                $open[] = $class;
            }
        }
        sort($open);

        $this->assertSame(self::OPEN_WHILE_FROZEN_PAGES, $open);
    }

    public function testTheActionExitsAreExactlyTheDeclaredOnes(): void
    {
        $exits = [];
        foreach ($this->classesDeclaring('const array FROZEN_EXIT_ACTIONS') as $class) {
            if ($class !== AbstractPage::class && $class !== AbstractAgent::class) {
                $exits[$class] = $class::FROZEN_EXIT_ACTIONS;
            }
        }
        ksort($exits);

        $this->assertSame(self::FROZEN_EXIT_ACTIONS, $exits);
    }

    public function testEveryAgentExitIsAnActionOfItsOwner(): void
    {
        foreach (self::FROZEN_EXIT_ACTIONS as $class => $actions) {
            foreach ($actions as $action) {
                $this->assertArrayHasKey($action, $class::AGENT_ACTIONS, "{$class} lists {$action} it does not own");
            }
        }
    }

    /**
     * Finds the framework classes whose own source declares something, without loading any other file.
     *
     * Read as text first, so a script or a stub that shares the tree is never executed by the census.
     *
     * @param string $declaration Text the class body declares
     * @return list<class-string> Classes declaring it, derived from the PSR-4 layout
     */
    private function classesDeclaring(string $declaration): array
    {
        $root = dirname(__DIR__, 2) . '/backend';
        $classes = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (!str_contains((string)file_get_contents($file->getPathname()), $declaration)) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
            $class = 'Hilos\\' . str_replace('/', '\\', $relative);
            $this->assertTrue(class_exists($class) || interface_exists($class), "{$class} is not a loadable class");
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * Collects the framework classes of one kind under a backend directory.
     *
     * Derives class names from the PSR-4 layout (Hilos\ => framework/backend/) and keeps only
     * descendants of the given base, so DTOs, enums and helpers that share a directory do not join.
     *
     * @template T of object
     * @param string $directory Directory under framework/backend, empty for all of it
     * @param class-string<T> $base Base class every collected class descends from
     * @return list<class-string<T>> Classes found
     */
    private function frameworkClasses(string $directory, string $base): array
    {
        $root = dirname(__DIR__, 2) . '/backend';
        $scanned = $directory === '' ? $root : "{$root}/{$directory}";
        $classes = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanned)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
            $class = 'Hilos\\' . str_replace('/', '\\', $relative);
            if (class_exists($class) && is_subclass_of($class, $base)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
