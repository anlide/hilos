<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupSpacePolicy;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Hilos;
use Hilos\Tests\Unit\EnvAccessorTestCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for reading the free-space policy from the environment.
 *
 * Backed by the shared mutable test catalog ({@see EnvAccessorTestCatalog}) so the three keys can
 * be exercised without the demo catalog: it pins that defaults apply when the vars are unset and
 * that explicit values are read back in the right types.
 */
final class BackupSpacePolicyTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        EnvAccessorTestCatalog::$catalog = [
            EnvConstants::BACKUP_SPACE_MARGIN->name => $this->entry(EnvCatalogConstants::TYPE_FLOAT, 1.5),
            EnvConstants::BACKUP_MIN_FREE_BYTES->name => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 1073741824),
            EnvConstants::BACKUP_REFUSE_WITHOUT_ESTIMATE->name => $this->entry(EnvCatalogConstants::TYPE_BOOLEAN, false),
        ];
        Hilos::$env = new EnvAccessor(EnvAccessorTestCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        EnvAccessorTestCatalog::$catalog = [];
        putenv(EnvConstants::BACKUP_SPACE_MARGIN->name);
        putenv(EnvConstants::BACKUP_MIN_FREE_BYTES->name);
        putenv(EnvConstants::BACKUP_REFUSE_WITHOUT_ESTIMATE->name);
        parent::tearDown();
    }

    public function testFromEnvReadsCatalogDefaults(): void
    {
        $policy = BackupSpacePolicy::fromEnv();

        $this->assertSame(1.5, $policy->spaceMargin);
        $this->assertSame(1073741824, $policy->minFreeBytes);
        $this->assertFalse($policy->refuseWithoutEstimate);
    }

    public function testFromEnvReadsExplicitValues(): void
    {
        putenv(EnvConstants::BACKUP_SPACE_MARGIN->name . '=2.25');
        putenv(EnvConstants::BACKUP_MIN_FREE_BYTES->name . '=2048');
        putenv(EnvConstants::BACKUP_REFUSE_WITHOUT_ESTIMATE->name . '=true');

        $policy = BackupSpacePolicy::fromEnv();

        $this->assertSame(2.25, $policy->spaceMargin);
        $this->assertSame(2048, $policy->minFreeBytes);
        $this->assertTrue($policy->refuseWithoutEstimate);
    }

    /**
     * @param string $type Catalog value type
     * @param mixed $default Default value
     * @return array<string, mixed> Catalog entry with the default applied when the var is unset
     */
    private function entry(string $type, mixed $default): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => $type,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => true,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];
    }
}
