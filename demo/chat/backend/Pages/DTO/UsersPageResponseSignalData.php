<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * UsersPageResponseSignalData - Page scope payload snapshot of a users table.
 *
 * Carries the `{page, payload: {tables: {users: {rows}}}}` wire form of the
 * page_response signal for the two users-table pages (admin_users and
 * hilos_users): the page key lets the frontend drop a late signal for a page
 * it has left, and each row composes one `user` entity slot the frontend
 * normalizer upserts into the page entity store. The user fragment is the
 * curated `{id, name}` projection.
 * Target client ID is handled by WebSocketSignalData wrapper for routing.
 */
final class UsersPageResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string payload = 'payload';
    public const string tables = 'tables';
    public const string users = 'users';
    public const string rows = 'rows';
    public const string rowKey = 'rowKey';
    public const string slots = 'slots';
    public const string user = 'user';
    public const string id = 'id';
    public const string name = 'name';

    /**
     * Creates users page response signal data.
     *
     * @param string $pageKey Subscribed page key echoed on the signal
     * @param list<array{id: int, name: string}> $userRows Users-table row fragments in snapshot order
     */
    public function __construct(
        public readonly string $pageKey,
        public readonly array $userRows,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::page => $this->pageKey,
            self::payload => [
                self::tables => [
                    self::users => [
                        self::rows => array_map(
                            static fn (array $userRow): array => [
                                self::rowKey => $userRow[self::id],
                                self::slots => [
                                    self::user => $userRow,
                                ],
                            ],
                            $this->userRows,
                        ),
                    ],
                ],
            ],
        ];
    }

    /**
     * Create DTO from wire payload.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::payload] ?? null;
        $tables = is_array($payload) ? ($payload[self::tables] ?? null) : null;
        $usersTable = is_array($tables) ? ($tables[self::users] ?? null) : null;
        $rows = is_array($usersTable) ? ($usersTable[self::rows] ?? null) : null;
        if (!is_array($rows)) {
            $rows = [];
        }

        $userRows = [];
        foreach ($rows as $row) {
            $userFragment = is_array($row) ? ($row[self::slots][self::user] ?? null) : null;
            if (!is_array($userFragment)) {
                continue;
            }
            $userRows[] = [
                self::id => (int)($userFragment[self::id] ?? 0),
                self::name => (string)($userFragment[self::name] ?? ''),
            ];
        }

        return new static(
            pageKey: (string)($data[self::page] ?? ''),
            userRows: $userRows,
        );
    }
}
