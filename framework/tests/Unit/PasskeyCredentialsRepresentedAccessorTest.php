<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Collection\PasskeyCredentials as ObjectPasskeyCredentials;
use Hilos\Database\View\Collection\PasskeyCredentials as ViewPasskeyCredentials;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Regression guard for the passkey-credentials ceremony API reachable through
 * the represented accessor `Hilos::$db->passkeyCredentials`.
 *
 * The register/login handlers call the object-layer ceremony primitives
 * (createFromRegistration / findByCredentialId / listByUser / findUserByUserHandle)
 * *through* the represented collection, which is the read-facing
 * {@see ViewPasskeyCredentials}. A first cut declared those primitives only on
 * the object collection, so every passkey action fatally hit an undefined method
 * on the represented view collection. This test locks the bridge in place: the
 * represented accessor must be the view collection and must itself declare each
 * ceremony method with a signature matching the object collection's.
 */
final class PasskeyCredentialsRepresentedAccessorTest extends TestCase
{
    /**
     * Ceremony primitives the demo action handlers invoke through
     * `Hilos::$db->passkeyCredentials`.
     *
     * @var list<string>
     */
    private const array CEREMONY_METHODS = [
        'createFromRegistration',
        'findByCredentialId',
        'listByUser',
        'findUserByUserHandle',
    ];

    public function testRepresentedAccessorIsTheViewCollection(): void
    {
        $context = new class extends HilosDbContext {};
        $context->configure();

        // LAZY_STRATEGY_KEY: this read touches no database.
        self::assertInstanceOf(ViewPasskeyCredentials::class, $context->passkeyCredentials);
    }

    public function testRepresentedAccessorBridgesEveryCeremonyMethod(): void
    {
        foreach (self::CEREMONY_METHODS as $method) {
            self::assertTrue(
                method_exists(ViewPasskeyCredentials::class, $method),
                "represented passkey accessor must expose {$method}()",
            );

            $bridge = new ReflectionMethod(ViewPasskeyCredentials::class, $method);
            self::assertTrue($bridge->isPublic(), "{$method}() must be public");
            self::assertSame(
                ViewPasskeyCredentials::class,
                $bridge->getDeclaringClass()->getName(),
                "{$method}() must be declared on the view collection, not resolved by magic",
            );

            $primitive = new ReflectionMethod(ObjectPasskeyCredentials::class, $method);
            self::assertSame(
                $primitive->getNumberOfParameters(),
                $bridge->getNumberOfParameters(),
                "{$method}() parameter count must match the object-collection primitive",
            );
            self::assertSame(
                self::returnTypeName($primitive),
                self::returnTypeName($bridge),
                "{$method}() return type must match the object-collection primitive",
            );
        }
    }

    /**
     * Renders a method's declared return type as a comparable string.
     *
     * @param ReflectionMethod $method Method to inspect
     * @return ?string Return type name (nullable-prefixed), or null when untyped
     */
    private static function returnTypeName(ReflectionMethod $method): ?string
    {
        $type = $method->getReturnType();
        if (!$type instanceof ReflectionNamedType) {
            return $type === null ? null : (string)$type;
        }

        // external-boundary: the neutral element of the type spelling — a non-nullable type carries no mark
        return ($type->allowsNull() ? '?' : '') . $type->getName();
    }
}
