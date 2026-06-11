<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Pages\DTO\UsersPageResponseSignalData;
use Hilos\Core\Router\SignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for users page response transport.
 */
final class UsersPageResponseSignalDataTest extends TestCase
{
    public function testImplementsSignalDataInterface(): void
    {
        $data = new UsersPageResponseSignalData(pageKey: 'admin_users', userRows: []);

        $this->assertInstanceOf(SignalDataInterface::class, $data);
    }

    public function testPayloadCarriesPageKeyAndUsersTableRows(): void
    {
        $data = new UsersPageResponseSignalData(pageKey: 'admin_users', userRows: [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $this->assertSame(
            [
                'page' => 'admin_users',
                'payload' => [
                    'tables' => [
                        'users' => [
                            'rows' => [
                                [
                                    'rowKey' => 1,
                                    'slots' => ['user' => ['id' => 1, 'name' => 'Alice']],
                                ],
                                [
                                    'rowKey' => 2,
                                    'slots' => ['user' => ['id' => 2, 'name' => 'Bob']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $data->toArray(),
        );
    }

    public function testEmptySnapshotKeepsRowsList(): void
    {
        $data = new UsersPageResponseSignalData(pageKey: 'hilos_users', userRows: []);

        $this->assertSame(
            [],
            $data->toArray()['payload']['tables']['users']['rows'],
        );
    }

    public function testRoundtripPreservesPayload(): void
    {
        $data = new UsersPageResponseSignalData(pageKey: 'hilos_users', userRows: [
            ['id' => 7, 'name' => 'User 7'],
        ]);

        $restored = UsersPageResponseSignalData::fromArray($data->toArray());

        $this->assertSame('hilos_users', $restored->pageKey);
        $this->assertSame([['id' => 7, 'name' => 'User 7']], $restored->userRows);
        $this->assertSame($data->toArray(), $restored->toArray());
    }
}
