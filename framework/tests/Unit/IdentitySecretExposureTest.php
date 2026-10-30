<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Identity;
use Hilos\Database\View\Item\Identity as ViewIdentity;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * The password hash stays outside the object/view surface and serialization.
 * Nullable metadata crosses those boundaries with its declared scalar types.
 */
final class IdentitySecretExposureTest extends TestCase
{
    public function testNullableMetadataRoundtripsWithoutExposingTheSecret(): void
    {
        $entity = EntityIdentity::fromRow([
            'id' => 7,
            'user_id' => 42,
            'type' => IdentityType::PASSWORD,
            'identifier' => 'reader@example.test',
            'provider' => null,
            'verified' => 1,
            'password_changed_at' => null,
            'provider_name' => null,
            'secret' => 'db-only-hash',
        ]);
        $identity = Identity::fromEntity($entity);
        $view = new ViewIdentity($identity);

        $this->assertSame([
            'id' => 7,
            'userId' => 42,
            'type' => IdentityType::PASSWORD,
            'identifier' => 'reader@example.test',
            'provider' => null,
            'verified' => true,
            'passwordChangedAt' => null,
            'providerName' => null,
        ], $identity->toArray());
        $this->assertNull($view->passwordChangedAt);
        $this->assertNull($view->providerName);
        $this->assertArrayNotHasKey('secret', $entity->toArray());

        $identity->passwordChangedAt = 1792742400123;
        $identity->providerName = 'Reader Name';

        $this->assertSame(1792742400123, $entity->password_changed_at);
        $this->assertSame(1792742400123, $view->passwordChangedAt);
        $this->assertSame('Reader Name', $view->providerName);
        $this->assertSame(1792742400123, $identity->toArray()['passwordChangedAt']);
        $this->assertSame('Reader Name', $identity->toArray()['providerName']);
        $this->assertArrayNotHasKey('secret', $identity->toArray());

        $identity->passwordChangedAt = null;
        $identity->providerName = null;
        $this->assertNull($view->passwordChangedAt);
        $this->assertNull($view->providerName);
    }

    public function testPasswordMomentDoesNotAcceptASqlDate(): void
    {
        $identity = Identity::create();

        $this->expectException(TypeError::class);
        $identity->passwordChangedAt = '2026-10-23 06:50:22';
    }

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
