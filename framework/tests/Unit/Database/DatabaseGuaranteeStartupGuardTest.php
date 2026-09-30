<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\DatabaseGuarantee;
use Hilos\Database\DatabaseGuaranteeStartupGuard;
use Hilos\Database\Exception\UndeclaredDatabaseGuaranteeException;
use Hilos\Hilos as HilosFacade;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the startup gate that asks what a project's database guarantees (HIL-1206).
 *
 * The gate reads one constant off the facade it is handed, so each case is a facade of its own.
 * What is pinned is the shape of a refusal: it names every promise left out and nothing else.
 */
final class DatabaseGuaranteeStartupGuardTest extends TestCase
{
    public function testAFacadeDeclaringBothPromisesPasses(): void
    {
        $this->expectNotToPerformAssertions();

        DatabaseGuaranteeStartupGuard::assertDeclared(GuaranteeBothHilos::class);
    }

    public function testAFacadeDeclaringNoneIsRefusedNamingBoth(): void
    {
        $message = $this->refusalOf(GuaranteeNoneHilos::class);

        $this->assertStringContainsString(
            GuaranteeNoneHilos::class . ' does not declare what its database guarantees',
            $message,
        );
        $this->assertStringContainsString(
            '- DatabaseGuarantee::ONE_LOGICAL_DATABASE: ' . DatabaseGuarantee::ONE_LOGICAL_DATABASE->obligation(),
            $message,
        );
        $this->assertStringContainsString(
            '- DatabaseGuarantee::READ_AFTER_WRITE: ' . DatabaseGuarantee::READ_AFTER_WRITE->obligation(),
            $message,
        );
    }

    public function testAFacadeDeclaringOneIsRefusedNamingOnlyTheOther(): void
    {
        $message = $this->refusalOf(GuaranteeOneHilos::class);

        $this->assertStringContainsString('- DatabaseGuarantee::READ_AFTER_WRITE: ', $message);
        $this->assertStringNotContainsString('ONE_LOGICAL_DATABASE', $message);
    }

    public function testTheFrameworksOwnFacadeIsRefused(): void
    {
        // The base facade declares nothing on purpose: a project that forgot the constant is
        // stopped rather than assumed to keep the promises.
        $message = $this->refusalOf(HilosFacade::class);

        $this->assertStringContainsString('ONE_LOGICAL_DATABASE', $message);
        $this->assertStringContainsString('READ_AFTER_WRITE', $message);
    }

    /**
     * Runs the gate over a facade that must be refused and hands back what it said.
     *
     * @param class-string<HilosFacade> $hilosClass Facade to judge
     * @return string Message of the refusal
     */
    private function refusalOf(string $hilosClass): string
    {
        try {
            DatabaseGuaranteeStartupGuard::assertDeclared($hilosClass);
        } catch (UndeclaredDatabaseGuaranteeException $refusal) {
            return $refusal->getMessage();
        }

        $this->fail("{$hilosClass} was let through");
    }
}

/**
 * Facade standing in for a project that states both promises.
 *
 * Abstract because it carries a declaration and nothing else: the gate reads constants, and no
 * layer is ever built from this class.
 */
abstract class GuaranteeBothHilos extends HilosFacade
{
    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];
}

/**
 * Facade standing in for a project that states one promise of the two; abstract for the same reason.
 */
abstract class GuaranteeOneHilos extends HilosFacade
{
    protected const array DATABASE_GUARANTEES = [DatabaseGuarantee::ONE_LOGICAL_DATABASE];
}

/**
 * Facade standing in for a project that states none; abstract for the same reason.
 */
abstract class GuaranteeNoneHilos extends HilosFacade
{
}
