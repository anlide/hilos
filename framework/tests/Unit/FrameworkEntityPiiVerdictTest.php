<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Schema\EntitySchemaAudit;
use PHPUnit\Framework\TestCase;

/**
 * The framework answers for its own tables: every Entity it ships carries a verdict.
 *
 * A project's tables are covered by the coverage gate of a restore, which reads a live
 * schema and can therefore name what was forgotten. The framework's own eleven need no
 * schema to be checked - the verdict is a constant on the class - and checking them here
 * is both cheaper and earlier: a framework Entity shipped without one would make every
 * project that adopts it fail a restore over a table it did not write.
 *
 * The Entities are discovered rather than listed, so a newly shipped one cannot pass by
 * saying nothing.
 */
final class FrameworkEntityPiiVerdictTest extends TestCase
{
    /**
     * Lower bound on the discovery, so an empty scan cannot pass as a clean sweep. The
     * framework ships eleven Entities today; it is a floor, not the exact number.
     */
    private const int MIN_FRAMEWORK_ENTITIES = 11;

    public function testEveryFrameworkEntityCarriesAVerdict(): void
    {
        $entityClasses = EntitySchemaAudit::frameworkEntities();

        $this->assertGreaterThanOrEqual(
            self::MIN_FRAMEWORK_ENTITIES,
            count($entityClasses),
            'The Entity discovery came back short; it, not the verdicts, is what to look at first',
        );
        foreach ($entityClasses as $entityClass) {
            $this->assertTrue(
                defined("{$entityClass}::" . Entity::META_PII),
                "{$entityClass} declares no " . Entity::META_PII . ', so its table is unclassified and '
                . 'every project shipping it would be refused a restore',
            );
        }
    }

    public function testNoColumnIsCalledBothPersonalAndNotPersonal(): void
    {
        foreach (EntitySchemaAudit::frameworkEntities() as $entityClass) {
            $verdict = constant("{$entityClass}::" . Entity::META_PII);
            if ($verdict instanceof AnonymizationStrategy) {
                $this->assertFalse(
                    defined("{$entityClass}::" . Entity::META_PII_NOT_PERSONAL),
                    "{$entityClass} is purged whole, so no row of it survives to hold a non-personal column",
                );

                continue;
            }

            $notPersonal = defined("{$entityClass}::" . Entity::META_PII_NOT_PERSONAL)
                ? constant("{$entityClass}::" . Entity::META_PII_NOT_PERSONAL)
                : [];
            $this->assertSame(
                [],
                array_intersect(array_keys($verdict), $notPersonal),
                "{$entityClass} names a column both personal and not personal",
            );
        }
    }

    public function testEveryMappedColumnOfAnEntityIsAccountedFor(): void
    {
        foreach (EntitySchemaAudit::frameworkEntities() as $entityClass) {
            $verdict = constant("{$entityClass}::" . Entity::META_PII);
            if ($verdict instanceof AnonymizationStrategy) {
                continue;
            }

            $notPersonal = defined("{$entityClass}::" . Entity::META_PII_NOT_PERSONAL)
                ? constant("{$entityClass}::" . Entity::META_PII_NOT_PERSONAL)
                : [];
            $judged = [...array_keys($verdict), ...$notPersonal];
            // The ORM's own column list is the floor rather than the whole question: a
            // table may carry columns outside it (Identity::secret is the framework's own
            // case), and only a live schema knows about those.
            foreach (constant("{$entityClass}::" . Entity::META_COLUMNS) as $column) {
                $this->assertContains(
                    $column,
                    $judged,
                    "{$entityClass} says nothing about column [{$column}], which is neither personal "
                    . 'nor looked at',
                );
            }
        }
    }
}
