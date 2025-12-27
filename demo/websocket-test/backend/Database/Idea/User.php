<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Hilos\Database\Idea\IdeaObject as BaseIdea;
use Hilos\Database\Idea\IdeaCollection;

/**
 * User Idea
 * High-level abstraction with lazy loading and relationships
 *
 * @property-read ?int $id
 * @property-read string $name
 * @property-read ?string $theme
 * @property-read ?string $sessionToken
 * @property-read ?string $lastActivity
 */
final class User extends BaseIdea
{
    /** @var self[] Global cache of User ideas */
    private static array $users = [];

    private ObjectUser $objectUser;

    protected function __construct(ObjectUser &$objectUser)
    {
        parent::__construct();
        $this->objectUser = &$objectUser;
    }

    /**
     * Flush global cache
     */
    public static function flushCache(): void
    {
        self::$users = [];
    }

    /**
     * Get User idea instance (cached)
     */
    public static function get(ObjectUser &$objectUser): self
    {
        $id = $objectUser->id;

        if (!isset(self::$users[$id])) {
            self::$users[$id] = new self($objectUser);
        } elseif (self::$users[$id]->objectUser !== $objectUser) {
            // Object reference changed, recreate
            self::$users[$id] = new self($objectUser);
        }

        return self::$users[$id];
    }

    /**
     * Property getter (read-only access)
     */
    public function __get(string $name): IdeaCollection|int|string|bool|null
    {
        return match ($name) {
            ObjectUser::id => $this->objectUser->id,
            ObjectUser::name => $this->objectUser->name,
            ObjectUser::theme => $this->objectUser->theme,
            ObjectUser::sessionToken => $this->objectUser->sessionToken,
            ObjectUser::lastActivity => $this->objectUser->lastActivity,

            // Example of lazy loading relationships (implement when you have related entities)
            // 'orders' => $this->loadOrders(),
            // 'posts' => $this->loadPosts(),

            default => throw new \Exception("Property [{$name}] does not exist on IdeaUser"),
        };
    }

    /**
     * Convert to array
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectUser::id] = $this->objectUser->id;
        }

        $data[ObjectUser::name] = $this->objectUser->name;
        $data[ObjectUser::theme] = $this->objectUser->theme;
        $data[ObjectUser::sessionToken] = $this->objectUser->sessionToken;
        $data[ObjectUser::lastActivity] = $this->objectUser->lastActivity;

        return $data;
    }
}
