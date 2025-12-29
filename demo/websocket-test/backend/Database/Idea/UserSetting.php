<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Object\UserSetting as ObjectUserSetting;
use Hilos\Database\Idea\IdeaItem;
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
final class UserSetting extends IdeaItem
{
    /**
     * Public constructor - creates IdeaUserSetting from ObjectUserSetting instance
     * 
     * @param ObjectUserSetting $objectUserSetting ObjectUserSetting instance (reference)
     */
    public function __construct(ObjectUserSetting &$objectUserSetting)
    {
        parent::__construct($objectUserSetting);
    }

    /**
     * Get ObjectUserSetting instance
     * Returns the underlying ObjectUserSetting instance that this IdeaUserSetting wraps.
     * This is a reference to the Object stored in ObjectCollection in IdeaStorage.
     * 
     * @return ObjectUserSetting ObjectUserSetting instance (reference, not a copy)
     * @throws \RuntimeException If the underlying object is not an ObjectUserSetting instance
     */
    private function getObjectUserSetting(): ObjectUserSetting
    {
        $object = $this->getObject();
        if (!($object instanceof ObjectUserSetting)) {
            throw new \RuntimeException("Expected ObjectUserSetting instance");
        }
        return $object;
    }

    /**
     * Property getter (read-only access)
     */
    public function __get(string $name): IdeaUser|IdeaCollection|int|string|bool|null
    {
        $objectUserSetting = $this->getObjectUserSetting();

        return match ($name) {
            ObjectUserSetting::id => $objectUserSetting->id,
            ObjectUserSetting::userId => $objectUserSetting->userId,
            ObjectUserSetting::key => $objectUserSetting->key,
            ObjectUserSetting::value => $objectUserSetting->value,

            // Related collections
            'user' => $this->getUser(),

            default => throw new \Exception("Property [{$name}] does not exist on IdeaUserSetting"),
        };
    }

    /**
     * Get User idea for this UserSetting
     * Returns IdeaUser from IdeaStorage users collection
     * 
     * @return IdeaUser|null
     */
    private function getUser(): ?IdeaUser
    {
        $objectUserSetting = $this->getObjectUserSetting();
        $userId = $objectUserSetting->userId;

        if ($userId === null) {
            return null;
        }

        $storage = Idea::$storage;
        if ($storage === null) {
            throw new \RuntimeException("IdeaStorage not initialized");
        }

        // Get IdeaUser from IdeaStorage users collection
        $usersCollection = Idea::$idea->users;
        return $usersCollection[$userId] ?? null;
    }

    /**
     * Convert to array
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $objectUserSetting = $this->getObjectUserSetting();

        $data = [];

        if ($withId) {
            $data[ObjectUserSetting::id] = $objectUserSetting->id;
        }

        $data[ObjectUserSetting::userId] = $objectUserSetting->userId;
        $data[ObjectUserSetting::key] = $objectUserSetting->key;
        $data[ObjectUserSetting::value] = $objectUserSetting->value;

        return $data;
    }
}
