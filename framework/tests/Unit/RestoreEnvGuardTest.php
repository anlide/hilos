<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestoreEnvGuard;
use Hilos\Constants\AppEnv;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the restore ENV permission matrix.
 *
 * One test per matrix row rather than a data provider on purpose: each row is an owner
 * decision with its own rationale, and a failure should name the broken row directly.
 */
final class RestoreEnvGuardTest extends TestCase
{
    public function testProdIntoProdIsAllowed(): void
    {
        $result = RestoreEnvGuard::decide(AppEnv::PROD, AppEnv::PROD, force: false);

        $this->assertSame(RestoreEnvDecision::ALLOW, $result->decision);
        $this->assertNull($result->reason);
        $this->assertFalse($result->requiresForce);
    }

    public function testProdIntoStagingRequiresAnonymization(): void
    {
        $result = RestoreEnvGuard::decide(AppEnv::PROD, AppEnv::STAGING, force: false);

        $this->assertSame(RestoreEnvDecision::REQUIRE_ANONYMIZATION, $result->decision);
        $this->assertNotNull($result->reason);
    }

    public function testProdIntoDevRequiresAnonymization(): void
    {
        $result = RestoreEnvGuard::decide(AppEnv::PROD, AppEnv::DEV, force: false);

        $this->assertSame(RestoreEnvDecision::REQUIRE_ANONYMIZATION, $result->decision);
    }

    public function testDevIntoProdIsRefused(): void
    {
        $result = RestoreEnvGuard::decide(AppEnv::DEV, AppEnv::PROD, force: false);

        $this->assertSame(RestoreEnvDecision::REFUSE, $result->decision);
        $this->assertNotNull($result->reason);
        $this->assertFalse($result->requiresForce);
    }

    public function testStagingIntoProdIsRefused(): void
    {
        $result = RestoreEnvGuard::decide(AppEnv::STAGING, AppEnv::PROD, force: false);

        $this->assertSame(RestoreEnvDecision::REFUSE, $result->decision);
    }

    public function testForceDoesNotOverrideNonProdIntoProdRefusal(): void
    {
        $result = RestoreEnvGuard::decide(AppEnv::DEV, AppEnv::PROD, force: true);

        $this->assertSame(RestoreEnvDecision::REFUSE, $result->decision);
        $this->assertFalse($result->requiresForce);
    }

    public function testDevIntoDevIsAllowed(): void
    {
        $result = RestoreEnvGuard::decide(AppEnv::DEV, AppEnv::DEV, force: false);

        $this->assertSame(RestoreEnvDecision::ALLOW, $result->decision);
    }

    public function testUnknownIntoProdWithoutForceIsRefusedAndForceable(): void
    {
        $result = RestoreEnvGuard::decide(null, AppEnv::PROD, force: false);

        $this->assertSame(RestoreEnvDecision::REFUSE, $result->decision);
        $this->assertNotNull($result->reason);
        $this->assertTrue($result->requiresForce);
    }

    public function testUnknownIntoProdWithForceIsAllowed(): void
    {
        $result = RestoreEnvGuard::decide(null, AppEnv::PROD, force: true);

        $this->assertSame(RestoreEnvDecision::ALLOW, $result->decision);
    }

    public function testUnknownIntoDevIsAllowed(): void
    {
        $result = RestoreEnvGuard::decide(null, AppEnv::DEV, force: false);

        $this->assertSame(RestoreEnvDecision::ALLOW, $result->decision);
    }
}
