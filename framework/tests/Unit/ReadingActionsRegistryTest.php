<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Page\AbstractPage;
use Hilos\Pages\AbstractHilosProfileAgreementsHistoryPage;
use Hilos\Pages\AbstractHilosTermsPage;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pins the exact framework reading-action census (HIL-1290).
 *
 * Declaring a write as reading silently opens a write to somebody else's account during a
 * view-only takeover. The census is exact, not "at least", and every name belongs to its host.
 */
final class ReadingActionsRegistryTest extends TestCase
{
    /** Reading actions keyed by their declaring class, in class-name order. */
    private const array READING_ACTIONS = [
        AbstractUsersLibraryAgent::class => [
            HilosSignalConstants::HILOS_LEGAL_CONSENT,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW,
        ],
        AbstractHilosProfileAgreementsHistoryPage::class => [
            HilosSignalConstants::HILOS_LEGAL_REVISION_TEXT,
            HilosSignalConstants::HILOS_LEGAL_REVISION_CHANGES,
        ],
        AbstractHilosTermsPage::class => [HilosSignalConstants::HILOS_TERMS_REVISION_TEXT],
        AbstractHilosLogsViewPage::class => [
            HilosSignalConstants::LOGS_READ_LINES,
            HilosSignalConstants::LOGS_FOLLOW_START,
            HilosSignalConstants::LOGS_FOLLOW_STOP,
        ],
    ];

    public function testTheReadingActionsAreExactlyTheDeclaredOnes(): void
    {
        $reading = [];
        foreach ($this->classesDeclaring('const array READING_ACTIONS') as $class) {
            if ($class !== AbstractPage::class && $class !== AbstractAgent::class) {
                $reading[$class] = $class::READING_ACTIONS;
            }
        }
        ksort($reading);

        self::assertSame(self::READING_ACTIONS, $reading);
    }

    public function testEveryReadingActionBelongsToItsOwner(): void
    {
        foreach (self::READING_ACTIONS as $class => $actions) {
            $owned = is_subclass_of($class, AbstractPage::class) ? $class::ACTIONS : $class::AGENT_ACTIONS;
            foreach ($actions as $action) {
                self::assertArrayHasKey($action, $owned, "{$class} lists {$action} it does not own");
            }
            self::assertSame(array_values(array_unique($actions)), $actions, $class);
        }
    }

    /**
     * Reads source before loading a class, so scripts and stubs in the tree are not executed.
     *
     * @param string $declaration Text the class body declares
     * @return list<class-string> Declaring classes derived from the PSR-4 layout
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
            self::assertTrue(class_exists($class) || interface_exists($class), "{$class} is not a loadable class");
            $classes[] = $class;
        }

        return $classes;
    }
}
