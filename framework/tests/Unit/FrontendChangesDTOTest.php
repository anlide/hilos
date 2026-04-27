<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\DTO\FrontendChangesDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see FrontendChangesDTO}.
 */
final class FrontendChangesDTOTest extends TestCase
{
    public function testSerializesFrontendStateChanges(): void
    {
        $dto = new FrontendChangesDTO(
            full: ['users' => [['id' => 1, 'name' => 'Ada']]],
            updates: ['userPresence' => [['userId' => 1, 'presence' => 'online']]],
            deleted: ['userConnectionStats' => [1]],
            replaceFullKeys: ['users'],
        );

        $this->assertSame(
            [
                'full' => ['users' => [['id' => 1, 'name' => 'Ada']]],
                'updates' => ['userPresence' => [['userId' => 1, 'presence' => 'online']]],
                'deleted' => ['userConnectionStats' => [1]],
                'replaceFull' => ['users'],
            ],
            $dto->toArray(),
        );
    }

    public function testRoundtripKeepsOnlyValidCollectionShapes(): void
    {
        $dto = FrontendChangesDTO::fromArray([
            'full' => [
                'users' => [['id' => 1], 'bad'],
                7 => [['ignored' => true]],
            ],
            'updates' => ['userPresence' => [['userId' => 1]]],
            'deleted' => ['users' => [1, '2', ['bad']]],
            'replaceFull' => ['users', 3],
        ]);

        $this->assertSame(
            [
                'full' => ['users' => [['id' => 1]]],
                'updates' => ['userPresence' => [['userId' => 1]]],
                'deleted' => ['users' => [1, '2']],
                'replaceFull' => ['users'],
            ],
            $dto->toArray(),
        );
    }
}
