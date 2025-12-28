<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Hilos\Database\Idea\IdeaItem as BaseIdea;
use Hilos\Database\Idea\IdeaCollection;

/**
 * User Idea
 * High-level abstraction with lazy loading and relationships
 * 
 * Stores reference to ObjectUser instance.
 * Object instances are stored in ObjectCollection in IdeaStorage.
 *
 * @property-read ?int $id
 * @property-read string $name
 * @property-read ?string $theme
 * @property-read ?string $sessionToken
 * @property-read ?string $lastActivity
 */
final class User extends BaseIdea
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
     * Get ObjectUser instance
     * 
     * @return ObjectUser
     */
    private function getObjectUser(): ObjectUser
    {
        $object = $this->getObject();
        if (!($object instanceof ObjectUser)) {
            throw new \RuntimeException("Expected ObjectUser instance");
        }
        return $object;
    }

    /**
     * Get IdeaStorage instance
     * Override to use application-specific Idea class
     * 
     * @return \Demo\WebSocketTest\Database\IdeaStorage|null
     */
    protected function getStorage(): ?\Demo\WebSocketTest\Database\IdeaStorage
    {
        // Access IdeaStorage through application-specific Idea class
        return \Demo\WebSocketTest\Database\Idea::$storage ?? null;
    }

    /**
     * Property getter (read-only access)
     */
    public function __get(string $name): IdeaCollection|int|string|bool|null
    {
        $objectUser = $this->getObjectUser();

        return match ($name) {
            ObjectUser::id => $objectUser->id,
            ObjectUser::name => $objectUser->name,
            ObjectUser::theme => $objectUser->theme,
            ObjectUser::sessionToken => $objectUser->sessionToken,
            ObjectUser::lastActivity => $objectUser->lastActivity,

            // Example of lazy loading relationships:
            // 'orders' => $this->getOrders(),
            // 'posts' => $this->getPosts(),

            default => throw new \Exception("Property [{$name}] does not exist on IdeaUser"),
        };
    }

    /**
     * Convert to array
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $objectUser = $this->getObjectUser();

        $data = [];

        if ($withId) {
            $data[ObjectUser::id] = $objectUser->id;
        }

        $data[ObjectUser::name] = $objectUser->name;
        $data[ObjectUser::theme] = $objectUser->theme;
        $data[ObjectUser::sessionToken] = $objectUser->sessionToken;
        $data[ObjectUser::lastActivity] = $objectUser->lastActivity;

        return $data;
    }

    /**
     * Get related orders collection (example)
     * 
     * This demonstrates how to create related Idea collections dynamically.
     * The ObjectOrderCollection is retrieved from IdeaStorage, and IdeaOrderCollection
     * is created from it. If ObjectOrderCollection is already loaded, it uses those
     * Object instances; otherwise, lazy loading strategy applies.
     * 
     * @return IdeaCollection|null Related orders collection
     */
    /*
    public function getOrders(): ?IdeaCollection
    {
        $storage = $this->getStorage();
        if ($storage === null) {
            return null;
        }

        // Get ObjectOrderCollection from IdeaStorage
        // This may be already loaded or use lazy loading strategy
        $objectOrders = $storage->orders ?? null;
        if ($objectOrders === null) {
            return null;
        }

        // Create IdeaOrderCollection from ObjectOrderCollection
        // Object instances are passed as references, so IdeaOrder instances
        // will reference the same Object instances stored in IdeaStorage
        return \Demo\WebSocketTest\Database\IdeaCollection\Orders::init($objectOrders);
    }
    */
}
