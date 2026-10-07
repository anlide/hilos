<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Environment\EcommerceShopEnvCatalog;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use PHPUnit\Framework\TestCase;

/**
 * Checks the effective catalog after this demo replaces framework entries.
 */
final class EnvCatalogMetadataTest extends TestCase
{
    public function testFrameworkKeyClassificationsSurviveOverrides(): void
    {
        $framework = new EnvAccessor(EnvCatalogStub::class);
        $demo = new EnvAccessor(EcommerceShopEnvCatalog::class);

        foreach (array_keys(EnvCatalogStub::getCatalog()) as $key) {
            $this->assertSame($framework->sensitiveFor($key), $demo->sensitiveFor($key), $key);
            $this->assertSame($framework->perNodeFor($key), $demo->perNodeFor($key), $key);
            $this->assertSame(
                $framework->visibleInAdminViewMode($key),
                $demo->visibleInAdminViewMode($key),
                $key,
            );
        }
    }
}
