<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Identity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the identity layer's secret encapsulation (HIL-370, area 1e):
 * the password hash never leaves the identity layer. The object surface exposes
 * only the non-secret fields — the `secret` column is not a readable property and
 * cannot be written through the setter. The hash is reached only through the
 * layer's own verify/rehash primitives (targeted queries). The complementary
 * assertion that toArray() carries no `secret` key on a hydrated identity lives in
 * MainPageRegisterTest, where a persisted identity is available.
 */
final class IdentitySecretExposureTest extends TestCase
{
    /**
     * The secret hash is not a readable property on the object surface.
     *
     * @throws DatabaseException When the empty identity cannot be built
     */
    public function testSecretIsNotAReadableProperty(): void
    {
        $identity = Identity::create();

        $this->expectException(DatabaseException::class);
        /** @phpstan-ignore-next-line intentional access of a non-existent property */
        $identity->secret;
    }

    /**
     * The secret hash cannot be written through the object setter.
     *
     * @throws DatabaseException When the empty identity cannot be built
     */
    public function testSecretCannotBeSetThroughTheObjectSurface(): void
    {
        $identity = Identity::create();

        $this->expectException(DatabaseException::class);
        /** @phpstan-ignore-next-line intentional write of a non-existent property */
        $identity->secret = 'plaintext-or-hash';
    }
}
