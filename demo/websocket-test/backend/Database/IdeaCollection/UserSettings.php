<?php

namespace Demo\WebSocketTest\Database\IdeaCollection;

use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Idea\UserSetting as IdeaUserSetting;
use Demo\WebSocketTest\Database\Object\UserSetting as ObjectUserSetting;
use Demo\WebSocketTest\Database\ObjectCollection\UserSettings as ObjectUserSettings;
use Hilos\Database\Idea\IdeaCollection;
use Hilos\Database\Object\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use InvalidArgumentException;
use RuntimeException;

/**
 * UserSettings Idea Collection
 * Collection of UserSetting ideas with additional filtering methods
 */
final class UserSettings extends IdeaCollection
{
    // init() and initEmpty() are inherited from IdeaCollection
    // Override only if custom initialization logic is needed

    /**
     * Get ObjectCollection from IdeaStorage
     * Returns null for manual collections
     * 
     * @return Objects|null ObjectCollection instance from IdeaStorage, or null for manual collections
     */
    protected function getObjectCollection(): ?Objects
    {
        $storage = Idea::$storage;
        if ($storage === null) {
            throw new RuntimeException("IdeaStorage not initialized");
        }
        return $storage->userSettings;
    }

    /**
     * Create Idea instance from Object
     * 
     * @param Object_ $object Object instance (reference)
     * @return IdeaUserSetting
     */
    protected function createIdea(Object_ &$object): IdeaUserSetting
    {
        if (!($object instanceof ObjectUserSetting)) {
            throw new InvalidArgumentException("Object must be instance of ObjectUserSetting");
        }
        return new IdeaUserSetting($object);
    }

    /**
     * Convert to array with additional options
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        return parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation);
    }
}

