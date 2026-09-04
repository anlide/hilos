<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Tests\CodeStyle\ScannedRoots;
use Hilos\Tests\CodeStyle\SourceScanner;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The list of Entity classes the framework ships has exactly one source, and this
 * test holds all three sides of that claim.
 *
 * The source is {@see EntitySchemaAudit::frameworkEntities()}. A second copy of the
 * knowledge - a directory and a namespace kept by hand somewhere else - does not
 * announce itself when it falls behind: both lists keep working, they simply stop
 * describing the same set, and the audit quietly stops covering the Entity the
 * newer list never heard of. That is what happened to the framework's own
 * integration suite, which carried its own pair until HIL-698.
 *
 * Two axes close it, because neither covers the other. The door
 * {@see EntitySchemaAudit::discoverEntities()} judges the VALUE it is handed, so it
 * also catches a pair assembled by computation, and it catches it in a project that
 * lives outside this repository. The last case here judges the TEXT of the sources,
 * so it catches a hand-written walk of the directory that never called the door at
 * all. The hole both of them leave is named rather than hidden: a project outside
 * this repository that writes its own walk is seen by neither.
 *
 * No database is needed for any of it - discovery reads the filesystem, and the
 * refusal reads an argument.
 */
final class FrameworkEntitySingleSourceTest extends TestCase
{
    /** The framework's own Entity directory, relative to `framework/`. */
    private const string ENTITY_DIRECTORY = '/backend/Database/Entity/Item';

    /** The namespace of that directory, as the door refuses to be handed it. */
    private const string NAMESPACE_LITERAL = 'Hilos\\Database\\Entity\\Item';

    /** The tail of that directory as a path, as a hand-written walk would spell it. */
    private const string PATH_LITERAL = 'Database/Entity/Item';

    /** The refusal {@see EntitySchemaAudit::discoverEntities()} answers the framework pair with. */
    private const string REFUSAL_MESSAGE = 'The framework Entity namespace has a single source: call '
        . 'EntitySchemaAudit::frameworkEntities() instead of discovering it by hand';

    /** The one judged file allowed to write {@see self::NAMESPACE_LITERAL}: the door that owns the pair. */
    private const string NAMESPACE_HOME = 'framework/backend/Database/Schema/EntitySchemaAudit.php';

    /**
     * This file, which is not judged at all: it has to spell both literals in order to
     * look for them, so judging it would be judging the question rather than the answer.
     * It is therefore also the one place {@see self::PATH_LITERAL} may be written.
     */
    private const string PATH_HOME = 'framework/tests/Unit/FrameworkEntitySingleSourceTest.php';

    /**
     * Roots where {@see self::PATH_LITERAL} is judged, as prefixes of the root path.
     *
     * A demo is deliberately outside them: the very same tail there leads to the demo's
     * OWN Entity catalog, which is legitimate and stays
     * (`demo/<name>/tests/Integration/EntitySchemaConsistencyTest.php`). Only the framework
     * and the runner scripts are talking about the framework's directory when they
     * spell this path.
     *
     * @var array<int, string>
     */
    private const array PATH_JUDGED_ROOTS = ['framework/', 'scripts/'];

    /**
     * @return iterable<string, array{string}> Case name => [namespace handed to the door]
     */
    public static function frameworkNamespaceProvider(): iterable
    {
        yield 'with a trailing separator' => [self::NAMESPACE_LITERAL . '\\'];
        yield 'without a trailing separator' => [self::NAMESPACE_LITERAL];
        yield 'spelled in another case' => [strtolower(self::NAMESPACE_LITERAL) . '\\'];
    }

    /**
     * The discovery must answer the directory itself, entity for entity.
     *
     * The expected set is built here without asking the auditor anything, so the two
     * sides stay independent: a glob of the directory, the class name taken from the
     * file name, and the namespace read off {@see Entity} rather than written out.
     * The glob is flat on purpose - the directory has no subdirectory today, and the
     * day it grows one this test is what must be reconsidered, not the list.
     */
    public function testFrameworkEntitiesMatchTheEntityDirectory(): void
    {
        $namespace = substr(Entity::class, 0, (int)strrpos(Entity::class, '\\') + 1);

        $expected = [];
        foreach (glob(dirname(__DIR__, 2) . self::ENTITY_DIRECTORY . '/*.php') ?: [] as $file) {
            $entityClass = $namespace . basename($file, '.php');
            if ($entityClass === Entity::class) {
                continue;
            }
            $expected[] = $entityClass;
        }
        sort($expected);

        $this->assertSame(
            $expected,
            EntitySchemaAudit::frameworkEntities(),
            'The framework Entity list and the Entity directory disagree. A file added to the '
            . 'directory belongs in the list by itself; if the difference is a subdirectory, it is '
            . 'this test that needs revisiting, because it globs the directory flat.',
        );
    }

    /**
     * The door must refuse the framework pair and name the replacement in the refusal,
     * whether or not the caller spelled the namespace with its trailing separator, and
     * whatever case they spelled it in - PHP resolves a class name case-insensitively,
     * so a miscased namespace reaches exactly the same classes.
     *
     * @param string $namespace Namespace prefix handed to the door
     */
    #[DataProvider('frameworkNamespaceProvider')]
    public function testDiscoveryRefusesTheFrameworkNamespace(string $namespace): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(self::REFUSAL_MESSAGE);

        EntitySchemaAudit::discoverEntities(dirname(__DIR__, 2) . self::ENTITY_DIRECTORY, $namespace);
    }

    /**
     * Nobody outside the two named places writes the framework's Entity namespace or the
     * tail of its directory, so a second catalog cannot be assembled past the door.
     *
     * The roots are the ones the code-style guard reads ({@see ScannedRoots}), so this
     * case cannot fall behind them by keeping a list of its own. Only
     * `T_CONSTANT_ENCAPSED_STRING` is judged: the namespace of each Entity file, and the
     * prose of a neighboring test's docblock, both name the same thing without keeping
     * a catalog, and neither is a string.
     */
    public function testNoOtherPlaceNamesTheFrameworkEntityCatalog(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);

        $found = [];
        foreach (array_keys(ScannedRoots::all($repositoryRoot)) as $root) {
            $scanner = new SourceScanner($repositoryRoot . '/' . $root);
            foreach ($scanner->files() as $file) {
                $path = $root . '/' . $scanner->relativePath($file);
                if ($path === self::PATH_HOME) {
                    continue;
                }

                foreach (self::stringLiterals($file->getPathname()) as $line => $literals) {
                    foreach ($literals as $literal) {
                        if (str_contains($literal, self::NAMESPACE_LITERAL) && $path !== self::NAMESPACE_HOME) {
                            $found[] = self::NAMESPACE_LITERAL . " at {$path}:{$line}";
                        }
                        if (str_contains($literal, self::PATH_LITERAL) && self::judgesPathLiteral($root)) {
                            $found[] = self::PATH_LITERAL . " at {$path}:{$line}";
                        }
                    }
                }
            }
        }
        sort($found);

        $this->assertSame(
            [],
            $found,
            "A second copy of the framework's Entity catalog is being written by hand. The list "
            . 'comes from EntitySchemaAudit::frameworkEntities(); a project catalog goes through '
            . "EntitySchemaAudit::discoverEntities() with the project's own namespace.",
        );
    }

    /**
     * @param string $root Path of a scanned root, relative to the repository root
     * @return bool True when a file of this root spelling the path literal means the framework's directory
     */
    private static function judgesPathLiteral(string $root): bool
    {
        foreach (self::PATH_JUDGED_ROOTS as $judged) {
            if (str_starts_with($root . '/', $judged)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every quoted string of one PHP file, as it reads rather than as it is escaped.
     *
     * A namespace is written for the parser, with each separator doubled; collapsing the
     * pairs back is what lets one literal be compared against the namespace a reader sees.
     *
     * @param string $pathname Absolute path of the PHP file to read
     * @return array<int, array<int, string>> String literals of the file, keyed by line number
     */
    private static function stringLiterals(string $pathname): array
    {
        $literals = [];
        foreach (token_get_all((string)file_get_contents($pathname)) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $literals[$token[2]][] = str_replace('\\\\', '\\', $token[1]);
        }

        return $literals;
    }
}
