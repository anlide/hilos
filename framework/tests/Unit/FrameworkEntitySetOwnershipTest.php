<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Schema\EntitySchemaAudit;
use PHPUnit\Framework\TestCase;

/**
 * The framework answers for its own tables: every Entity it ships says whose set it is part of.
 *
 * A project's tables are refused at the startup of a node, which reads the collections an
 * installation mounted and can therefore name what was forgotten. The framework's own eleven
 * need no installation to be checked - the declaration is a constant on the class - and checking
 * them here is both cheaper and earlier: a framework Entity shipped without one would refuse the
 * startup of every project that adopts it, over a table that project did not write.
 *
 * The Entities are discovered rather than listed, so a newly shipped one cannot pass by saying
 * nothing.
 */
final class FrameworkEntitySetOwnershipTest extends TestCase
{
    /**
     * Lower bound on the discovery, so an empty scan cannot pass as a clean sweep. The
     * framework ships eleven Entities today; it is a floor, not the exact number.
     */
    private const int MIN_FRAMEWORK_ENTITIES = 11;

    public function testEveryFrameworkEntityDeclaresBothHalvesOfItsSet(): void
    {
        $entityClasses = EntitySchemaAudit::frameworkEntities();

        $this->assertGreaterThanOrEqual(
            self::MIN_FRAMEWORK_ENTITIES,
            count($entityClasses),
            'The Entity discovery came back short; it, not the declarations, is what to look at first',
        );
        foreach ($entityClasses as $entityClass) {
            $this->assertTrue(
                defined("{$entityClass}::" . Entity::META_SET_VIA),
                "{$entityClass} declares no " . Entity::META_SET_VIA . ', so nobody answers for the '
                . 'completeness of its set and every project shipping it is refused a startup',
            );
            $this->assertTrue(
                defined("{$entityClass}::" . Entity::META_SET_ROOT),
                "{$entityClass} declares no " . Entity::META_SET_ROOT . ', so a table hanging its set '
                . 'off this one cannot be told whether it may',
            );
        }
    }

    public function testEveryDeclaredOwnerColumnIsOneTheEntityHas(): void
    {
        foreach (EntitySchemaAudit::frameworkEntities() as $entityClass) {
            $column = constant("{$entityClass}::" . Entity::META_SET_VIA);
            if ($column === Entity::SET_STANDALONE) {
                continue;
            }

            $this->assertContains(
                $column,
                constant("{$entityClass}::" . Entity::META_COLUMNS),
                "{$entityClass} cuts its set by column [{$column}], which is not among its "
                . Entity::META_COLUMNS,
            );
        }
    }
}
