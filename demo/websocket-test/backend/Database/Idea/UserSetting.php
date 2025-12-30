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
 * @extends IdeaItem<ObjectUserSetting>
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
     * Property getter (read-only access)
     */
    public function __get(string $name): IdeaUser|IdeaCollection|int|string|bool|null
    {
        return match ($name) {
            ObjectUserSetting::id => $this->_object->id,
            ObjectUserSetting::userId => $this->_object->userId,
            ObjectUserSetting::key => $this->_object->key,
            ObjectUserSetting::value => $this->_object->value,

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
        $userId = $this->_object->userId;

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
        $data = [];

        if ($withId) {
            $data[ObjectUserSetting::id] = $this->_object->id;
        }

        $data[ObjectUserSetting::userId] = $this->_object->userId;
        $data[ObjectUserSetting::key] = $this->_object->key;
        $data[ObjectUserSetting::value] = $this->_object->value;

        return $data;
    }
}
