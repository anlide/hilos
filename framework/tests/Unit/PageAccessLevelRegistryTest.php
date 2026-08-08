<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Pages\AbstractHilosAboutPage;
use Hilos\Pages\AbstractHilosLicensePage;
use Hilos\Pages\AbstractHilosNotificationsPage;
use Hilos\Pages\AbstractHilosPrivacyPage;
use Hilos\Pages\AbstractHilosProfilePage;
use Hilos\Pages\AbstractHilosTermsPage;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pins the EXACT composition of access-level exceptions on the framework admin
 * surface, not merely the fact of a closed default: the dangerous change this
 * test exists to catch is a page quietly joining the PUBLIC or AUTHENTICATED
 * relaxation lists. Every page class under framework/backend/Pages that is not
 * named here must inherit ADMIN.
 */
final class PageAccessLevelRegistryTest extends TestCase
{
    /** Exact set of framework pages readable without a session. */
    private const array PUBLIC_PAGES = [
        AbstractHilosAboutPage::class,
        AbstractHilosLicensePage::class,
        AbstractHilosPrivacyPage::class,
        AbstractHilosTermsPage::class,
    ];

    /** Exact set of framework pages open to any signed-in user. */
    private const array AUTHENTICATED_PAGES = [
        AbstractHilosNotificationsPage::class,
        AbstractHilosProfilePage::class,
    ];

    public function testFrameworkPageAccessLevelsMatchTheDeclaredExceptionsExactly(): void
    {
        $byLevel = [
            PageAccessLevel::PUBLIC->value => [],
            PageAccessLevel::AUTHENTICATED->value => [],
            PageAccessLevel::ADMIN->value => [],
        ];
        foreach ($this->frameworkPageClasses() as $class) {
            $byLevel[$class::ACCESS_LEVEL->value][] = $class;
        }
        sort($byLevel[PageAccessLevel::PUBLIC->value]);
        sort($byLevel[PageAccessLevel::AUTHENTICATED->value]);

        $expectedPublic = self::PUBLIC_PAGES;
        sort($expectedPublic);
        $expectedAuthenticated = self::AUTHENTICATED_PAGES;
        sort($expectedAuthenticated);

        $this->assertSame($expectedPublic, $byLevel[PageAccessLevel::PUBLIC->value]);
        $this->assertSame($expectedAuthenticated, $byLevel[PageAccessLevel::AUTHENTICATED->value]);
        // Everything else on the surface must stay ADMIN; a sanity floor guards
        // against the scan silently matching nothing.
        $this->assertGreaterThan(50, count($byLevel[PageAccessLevel::ADMIN->value]));
    }

    /**
     * Collects every page class under framework/backend/Pages.
     *
     * Derives class names from the PSR-4 layout (Hilos\ => framework/backend/)
     * and keeps only AbstractPage descendants, so DTOs, enums, and helper
     * classes that share the directory do not join the census.
     *
     * @return list<class-string<AbstractPage>> Page classes on the framework admin surface
     */
    private function frameworkPageClasses(): array
    {
        $pagesDir = dirname(__DIR__, 2) . '/backend/Pages';
        $classes = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pagesDir)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($pagesDir) + 1, -strlen('.php'));
            $class = 'Hilos\\Pages\\' . str_replace('/', '\\', $relative);
            if (class_exists($class) && is_subclass_of($class, AbstractPage::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
