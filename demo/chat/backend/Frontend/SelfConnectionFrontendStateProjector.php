<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend;

use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Core\Router\DTO\FrontendChangesDTO;

/**
 * Builds frontend-state snapshots for the current browser connection singleton.
 */
final class SelfConnectionFrontendStateProjector
{
    public const string id = 'id';
    public const string ID_SELF = 'self';

    /**
     * Builds an authoritative frontend singleton snapshot for one connection.
     */
    public static function fullForConnection(Connection $connection): FrontendChangesDTO
    {
        return new FrontendChangesDTO(
            full: [
                FrontendStateCollectionKey::SELF_CONNECTION => [self::rowForConnection($connection)],
            ],
            replaceFullKeys: [FrontendStateCollectionKey::SELF_CONNECTION],
        );
    }

    /**
     * Appends the frontend singleton snapshot to an existing frontend state payload.
     */
    public static function appendFullForConnection(
        FrontendChangesDTO $frontend,
        Connection $connection,
    ): FrontendChangesDTO {
        $payload = $frontend->toArray();
        $payload['full'][FrontendStateCollectionKey::SELF_CONNECTION] = [self::rowForConnection($connection)];
        $payload['replaceFull'][] = FrontendStateCollectionKey::SELF_CONNECTION;

        return FrontendChangesDTO::fromArray($payload);
    }

    /**
     * @return array<string, mixed> Browser-safe frontend-state row
     */
    private static function rowForConnection(Connection $connection): array
    {
        return [
            self::id => self::ID_SELF,
            ...SelfConnectionProjector::forConnection($connection),
        ];
    }
}
