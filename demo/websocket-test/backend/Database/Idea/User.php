<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\IdeaCollection\UserSettings as IdeaUserSettings;
use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Hilos\Database\Filter\FilterBuilder;
use Hilos\Database\Filter\FilterOperator;
use Hilos\Database\Idea\IdeaItem;
use Hilos\Exception\DatabaseException;
use RuntimeException;

/**
 * User Idea
 * High-level abstraction with lazy loading and relationships
 *
 * Stores reference to ObjectUser instance.
 * Object instances are stored in ObjectCollection in IdeaStorage.
 *
 * @extends IdeaItem<ObjectUser>
 *
 * @property-read ?int $id User ID (primary key)
 * @property-read string $name User name
 * @property-read ?string $sessionToken User session token (32 hex characters)
 * @property-read ?string $lastActivity Last activity timestamp
 */
final class User extends IdeaItem
{
    /**
     * Public constructor - creates IdeaUser from ObjectUser instance
     *
     * @param ObjectUser $objectUser ObjectUser instance (reference)
     */
    public function __construct(ObjectUser &$objectUser)
    {
        parent::__construct($objectUser);
    }

    /**
     * Create UserSettings collection for this user
     * Creates manual collection and populates it with UserSettings from ObjectCollection in IdeaStorage
     * Uses filter system for filtering by userId
     *
     * @return IdeaUserSettings
     * @throws DatabaseException
     */
    private function createCollectionSettings(): IdeaUserSettings
    {
        $userId = $this->_object->id;

        // Create manual collection
        $settingsCollection = IdeaUserSettings::initEmpty();

        if ($userId === null) {
            // User has no ID, return empty collection
            return $settingsCollection;
        }

        // Get ObjectCollection from IdeaStorage
        $objectCollection = Idea::$storage->userSettings;

        // Create filter for userId
        $filter = (new FilterBuilder())
            ->where('userId', FilterOperator::EQUALS, $userId)
            ->build();

        // Get filtered collection
        $filtered = $objectCollection->filter($filter);

        // Backup iterator index before iteration
        $objectCollection->backupIndex();

        try {
            // Iterate through filtered collection
            foreach ($filtered as $objectUserSetting) {
                // Add Object to manual IdeaCollection (creates IdeaUserSetting automatically)
                $settingsCollection->addFromObject($objectUserSetting);
            }
        } finally {
            // Restore iterator index after iteration
            $objectCollection->restoreIndex();
        }

        return $settingsCollection;
    }

    /**
     * Property getter (read-only access)
     * Provides access to ObjectUser properties through IdeaUser interface.
     * Supports lazy loading of related collections.
     *
     * @param string $name Property name
     * @return int|string|IdeaUserSettings|null Property value or IdeaCollection for relationships
     * @throws RuntimeException If property does not exist
     */
    public function __get(string $name): int|string|IdeaUserSettings|null
    {
        return match ($name) {
            ObjectUser::id => $this->_object->id,
            ObjectUser::name => $this->_object->name,
            ObjectUser::sessionToken => $this->_object->sessionToken,
            ObjectUser::lastActivity => $this->_object->lastActivity,

            // Related collections
            'settings' => $this->createCollectionSettings(),

            default => parent::__get($name),
        };
    }

    /**
     * Convert to array representation
     *
     * @param bool $withId Include ID field in result
     * @param bool $idAsIndex Use ID as array key
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @return array<string, mixed> Array representation
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectUser::id] = $this->_object->id;
        }

        $data[ObjectUser::name] = $this->_object->name;
        $data[ObjectUser::sessionToken] = $this->_object->sessionToken;
        $data[ObjectUser::lastActivity] = $this->_object->lastActivity;

        return $data;
    }
}
