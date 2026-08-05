<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Feature\HilosFeature;
use Hilos\Hilos as HilosFacade;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for the feature declaration predicate.
 *
 * What is pinned here is the resolution rule, not the six real features: {@see
 * HilosFacade::hasFeature()} answers for the project facade that init() bound, not for the class
 * the call was written on. Framework gates call it bare - `Hilos::hasFeature(...)` - which loses
 * late static binding and would read the base declaration, an empty list, so a regression here
 * turns every gate silently off rather than failing anywhere visible.
 */
final class FeatureDeclarationTest extends TestCase
{
    /** @var string App facade class bound before the test, restored afterwards */
    private string $boundAppClass = HilosFacade::class;

    /**
     * Remembers the app class the process had bound.
     */
    protected function setUp(): void
    {
        $this->boundAppClass = HilosFacade::appClass();
    }

    /**
     * Restores the app class so a later test sees the process as it found it.
     */
    protected function tearDown(): void
    {
        $this->bindAppClass($this->boundAppClass);
    }

    public function testBareCallAnswersForTheBoundProjectFacade(): void
    {
        $this->bindAppClass(FeatureDeclarationTestHilos::class);

        $this->assertTrue(HilosFacade::hasFeature(HilosFeature::SETTINGS));
        $this->assertFalse(HilosFacade::hasFeature(HilosFeature::BACKUP));
    }

    public function testDeclarationIsReadBackInDeclarationOrder(): void
    {
        $this->bindAppClass(FeatureDeclarationTestHilos::class);

        $this->assertSame(
            [HilosFeature::SETTINGS, HilosFeature::NOTIFICATIONS],
            HilosFacade::features(),
        );
    }

    public function testProjectDeclaringNothingHasNoFeature(): void
    {
        $this->bindAppClass(FeatureDeclarationEmptyHilos::class);

        $this->assertSame([], HilosFacade::features());
        $this->assertFalse(HilosFacade::hasFeature(HilosFeature::SETTINGS));
    }

    /**
     * Binds a facade class the way init() does.
     *
     * The binding is a private static the facade sets while wiring the browser context, and no
     * layer is built here, so the test writes it directly rather than running init() for a
     * question that is answered from constants alone.
     *
     * @param class-string<HilosFacade> $hilosClass Facade class to bind as the app class
     */
    private function bindAppClass(string $hilosClass): void
    {
        $property = new ReflectionProperty(HilosFacade::class, 'appClass');
        $property->setValue(null, $hilosClass);
    }
}

/**
 * Facade standing in for a project that declares two features.
 *
 * Abstract because it carries a declaration and nothing else: the predicate is answered from
 * constants, no layer is ever built from this class, and leaving createDb() unimplemented says
 * so more plainly than a stub DB context that no test would reach.
 */
abstract class FeatureDeclarationTestHilos extends HilosFacade
{
    protected const array FEATURES = [HilosFeature::SETTINGS, HilosFeature::NOTIFICATIONS];
}

/**
 * Facade standing in for a project that declares none; abstract for the same reason.
 */
abstract class FeatureDeclarationEmptyHilos extends HilosFacade
{
}
