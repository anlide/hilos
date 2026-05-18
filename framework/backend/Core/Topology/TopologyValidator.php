<?php

declare(strict_types=1);

namespace Hilos\Core\Topology;

use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Hilos;

/**
 * Validates project topology registry constants before runtime layers use them.
 */
final class TopologyValidator
{
    /**
     * Validates topology constants declared by a Hilos facade subclass.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @throws InvalidTopologyException When topology constants are inconsistent
     */
    public function validate(string $hilosClass): void
    {
        $errors = [];
        $pages = $this->constantArray($hilosClass, 'PAGES', $errors);
        $pageRoutes = $this->constantArray($hilosClass, 'PAGE_ROUTES', $errors);
        $tables = $this->constantArray($hilosClass, 'TABLES', $errors);
        $browserTables = $this->constantArray($hilosClass, 'BROWSER_TABLES', $errors);
        $pageTables = $this->constantArray($hilosClass, 'PAGE_TABLES', $errors);

        $this->validatePages($pages, $errors);
        $this->validateRegisteredTables($tables, $errors);
        $this->validateBrowserTables($browserTables, $errors);
        $this->validatePageRoutes($pages, $pageRoutes, $errors);
        $this->validatePageTables($pages, $tables, $browserTables, $pageTables, $errors);

        if ($errors !== []) {
            throw InvalidTopologyException::forErrors($hilosClass, $errors);
        }
    }

    /**
     * Reads an array topology constant from a facade class.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param string $constant Constant name
     * @param list<string> $errors Validation error accumulator
     * @return array<mixed, mixed> Constant value, or an empty array when invalid
     */
    private function constantArray(string $hilosClass, string $constant, array &$errors): array
    {
        $name = "{$hilosClass}::{$constant}";
        if (!defined($name)) {
            return [];
        }

        $value = constant($name);
        if (!is_array($value)) {
            $errors[] = "{$constant} must be an array";
            return [];
        }

        return $value;
    }

    /**
     * Validates page registry keys and page classes.
     *
     * @param array<mixed, mixed> $pages Page registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePages(array $pages, array &$errors): void
    {
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page)) {
                $errors[] = 'PAGES contains a non-string page key';
                continue;
            }

            if (!$this->isExistingClassString($pageClass, "PAGES[{$page}]", $errors)) {
                continue;
            }

            if (!is_subclass_of($pageClass, AbstractPage::class)) {
                $errors[] = "PAGES[{$page}] class {$pageClass} must extend " . AbstractPage::class;
                continue;
            }

            /** @var class-string<AbstractPage> $pageClass */
            $classPage = $pageClass::PAGE;
            if ($classPage !== $page) {
                $errors[] = "PAGES[{$page}] key must match {$pageClass}::PAGE ({$classPage})";
            }

            if (array_key_exists(BrowserConfigKey::TABLES, $pageClass::BROWSER)) {
                $errors[] = "PAGES[{$page}] class {$pageClass} must declare page-table bindings in PAGE_TABLES, not BROWSER['" . BrowserConfigKey::TABLES . "']";
            }
        }
    }

    /**
     * Validates registered table classes.
     *
     * @param array<mixed, mixed> $tables Registered table registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateRegisteredTables(array $tables, array &$errors): void
    {
        foreach ($tables as $table => $tableClass) {
            if (!is_string($table)) {
                $errors[] = 'TABLES contains a non-string table key';
                continue;
            }

            if (!$this->isExistingClassString($tableClass, "TABLES[{$table}]", $errors)) {
                continue;
            }

            if (!is_subclass_of($tableClass, TableDefinition::class)) {
                $errors[] = "TABLES[{$table}] class {$tableClass} must extend " . TableDefinition::class;
            }
        }
    }

    /**
     * Validates browser-only table registry keys and config classes.
     *
     * @param array<mixed, mixed> $browserTables Browser-only table registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateBrowserTables(array $browserTables, array &$errors): void
    {
        foreach ($browserTables as $table => $tableClass) {
            if (!is_string($table)) {
                $errors[] = 'BROWSER_TABLES contains a non-string table key';
                continue;
            }

            if (!$this->isExistingClassString($tableClass, "BROWSER_TABLES[{$table}]", $errors)) {
                continue;
            }

            if (!defined("{$tableClass}::TABLE")) {
                $errors[] = "BROWSER_TABLES[{$table}] class {$tableClass} must declare TABLE";
                continue;
            }

            $classTable = constant("{$tableClass}::TABLE");
            if (!is_string($classTable)) {
                $errors[] = "BROWSER_TABLES[{$table}] class {$tableClass}::TABLE must be a string";
                continue;
            }

            if ($classTable !== $table) {
                $errors[] = "BROWSER_TABLES[{$table}] key must match {$tableClass}::TABLE ({$classTable})";
            }

            if (!defined("{$tableClass}::BROWSER")) {
                $errors[] = "BROWSER_TABLES[{$table}] class {$tableClass} must declare BROWSER";
                continue;
            }

            if (!is_array(constant("{$tableClass}::BROWSER"))) {
                $errors[] = "BROWSER_TABLES[{$table}] class {$tableClass}::BROWSER must be an array";
            }
        }
    }

    /**
     * Validates page route declarations against registered pages.
     *
     * @param array<mixed, mixed> $pages Page registry
     * @param array<mixed, mixed> $pageRoutes Page route registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageRoutes(array $pages, array $pageRoutes, array &$errors): void
    {
        foreach ($pageRoutes as $page => $_agentType) {
            if (!is_string($page)) {
                $errors[] = 'PAGE_ROUTES contains a non-string page key';
                continue;
            }

            if (!array_key_exists($page, $pages)) {
                $errors[] = "PAGE_ROUTES[{$page}] references a page missing from PAGES";
            }
        }
    }

    /**
     * Validates page-table bindings against registered pages and tables.
     *
     * @param array<mixed, mixed> $pages Page registry
     * @param array<mixed, mixed> $tables Registered table registry
     * @param array<mixed, mixed> $browserTables Browser-only table registry
     * @param array<mixed, mixed> $pageTables Page-table binding registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageTables(
        array $pages,
        array $tables,
        array $browserTables,
        array $pageTables,
        array &$errors,
    ): void {
        foreach ($pageTables as $page => $bindings) {
            if (!is_string($page)) {
                $errors[] = 'PAGE_TABLES contains a non-string page key';
                continue;
            }

            if (!array_key_exists($page, $pages)) {
                $errors[] = "PAGE_TABLES[{$page}] references a page missing from PAGES";
            }

            if (!is_array($bindings)) {
                $errors[] = "PAGE_TABLES[{$page}] must be an array of table bindings";
                continue;
            }

            foreach ($bindings as $table => $config) {
                if (!is_string($table)) {
                    $errors[] = "PAGE_TABLES[{$page}] contains a non-string table key";
                    continue;
                }

                if (!array_key_exists($table, $tables) && !array_key_exists($table, $browserTables)) {
                    $errors[] = "PAGE_TABLES[{$page}][{$table}] references a table missing from TABLES and BROWSER_TABLES";
                }

                if (!is_array($config)) {
                    $errors[] = "PAGE_TABLES[{$page}][{$table}] config must be an array";
                }
            }
        }
    }

    /**
     * Checks a topology value is an existing class string.
     *
     * @param mixed $class Candidate class value
     * @param string $path Topology path for error messages
     * @param list<string> $errors Validation error accumulator
     * @return bool True when the value is an existing class string
     */
    private function isExistingClassString(mixed $class, string $path, array &$errors): bool
    {
        if (!is_string($class) || $class === '') {
            $errors[] = "{$path} must be a non-empty class string";
            return false;
        }

        if (!class_exists($class)) {
            $errors[] = "{$path} class {$class} does not exist";
            return false;
        }

        return true;
    }
}
