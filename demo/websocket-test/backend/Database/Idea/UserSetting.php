<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\UserSetting as ObjectUserSetting;
use Hilos\Database\Idea\IdeaItem as BaseIdea;
use Hilos\Database\Idea\IdeaCollection;

/**
 * UserSetting Idea
 * High-level abstraction with lazy loading and relationships
 *
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $key
 * @property-read ?string $value
 */
final class UserSetting extends BaseIdea
{
    /** @var self[] Global cache of UserSetting ideas */
    private static array $usersettings = [];

    private ObjectUserSetting $objectUserSetting;

    protected function __construct(ObjectUserSetting &$objectUserSetting)
    {
        parent::__construct();
        $this->objectUserSetting = &$objectUserSetting;
    }

    /**
     * Flush global cache
     */
    public static function flushCache(): void
    {
        self::$usersettings = [];
    }

    /**
     * Get UserSetting idea instance (cached)
     */
    public static function get(ObjectUserSetting &$objectUserSetting): self
    {
        $id = $objectUserSetting->id;

        if (!isset(self::$usersettings[$id])) {
            self::$usersettings[$id] = new self($objectUserSetting);
        } elseif (self::$usersettings[$id]->objectUserSetting !== $objectUserSetting) {
            // Object reference changed, recreate
            self::$usersettings[$id] = new self($objectUserSetting);
        }

        return self::$usersettings[$id];
    }

    /**
     * Property getter (read-only access)
     */
    public function __get(string $name): IdeaCollection|int|string|bool|null
    {
        return match ($name) {
            ObjectUserSetting::id => $this->objectUserSetting->id,
            ObjectUserSetting::userId => $this->objectUserSetting->userId,
            ObjectUserSetting::key => $this->objectUserSetting->key,
            ObjectUserSetting::value => $this->objectUserSetting->value,

            // Example of lazy loading relationships (implement when you have related entities)
            // 'orders' => $this->loadOrders(),
            // 'posts' => $this->loadPosts(),

            default => throw new \Exception("Property [{$name}] does not exist on IdeaUserSetting"),
        };
    }

    /**
     * Convert to array
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectUserSetting::id] = $this->objectUserSetting->id;
        }

        $data[ObjectUserSetting::userId] = $this->objectUserSetting->userId;
        $data[ObjectUserSetting::key] = $this->objectUserSetting->key;
        $data[ObjectUserSetting::value] = $this->objectUserSetting->value;

        return $data;
    }
}
