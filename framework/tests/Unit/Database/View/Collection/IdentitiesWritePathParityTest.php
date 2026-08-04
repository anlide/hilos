<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\View\Collection;

use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\View\Collection\Identities as ViewIdentities;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Unit guard for the identity write-path delegation seam (HIL-327).
 *
 * Every `create*Identity*` write primitive on the object collection must have a
 * matching read-facing delegator on the view collection, since callers write
 * through {@see Hilos::$db}->identities (the view surface). The bulk-seed
 * regression that motivated this test added createPasswordIdentityWithHash() to
 * the object layer only, so the CLI's view-collection call fataled at runtime with
 * no framework-suite coverage catching it. This reflection parity check makes the
 * gap fail here — before a DB is even needed — for any future write primitive too.
 */
final class IdentitiesWritePathParityTest extends TestCase
{
    /**
     * For each object-layer create-write primitive there is a view delegator with an
     * identical parameter signature (names, types, order).
     */
    public function testEveryObjectCreateWritePrimitiveHasAViewDelegator(): void
    {
        $writeMethods = array_filter(
            get_class_methods(ObjectIdentities::class),
            static fn (string $name): bool => str_starts_with($name, 'create'),
        );

        self::assertNotEmpty($writeMethods, 'expected object collection to expose create* write primitives');

        foreach ($writeMethods as $name) {
            self::assertTrue(
                method_exists(ViewIdentities::class, $name),
                "view collection is missing a delegator for {$name}()",
            );

            self::assertSame(
                $this->signature(new ReflectionMethod(ObjectIdentities::class, $name)),
                $this->signature(new ReflectionMethod(ViewIdentities::class, $name)),
                "view delegator {$name}() parameter signature diverges from the object primitive",
            );
        }
    }

    /**
     * Renders a method's parameter list as `type $name` fragments for signature comparison.
     *
     * @param ReflectionMethod $method Method whose parameters to render
     * @return list<string> Ordered `type $name` fragments (return type excluded)
     */
    private function signature(ReflectionMethod $method): array
    {
        $fragments = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : (string)$type;
            $fragments[] = $typeName . ' $' . $parameter->getName();
        }

        return $fragments;
    }
}
