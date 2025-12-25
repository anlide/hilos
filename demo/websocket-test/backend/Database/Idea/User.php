<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Hilos\Database\Idea\IdeaObject as BaseIdea;
use Hilos\Database\Idea\IdeaCollection;

/**
 * User Idea
 * High-level abstraction with lazy loading and relationships
 *
 * @property-read int $id
 * @property-read string $name
 * @property-read ?string $theme
 */
final class User extends BaseIdea
{
    /** @var self[] Global cache of User ideas */
    private static array $users = [];

    private ObjectUser $objectUser;

    protected function __construct(ObjectUser &$user)
    {
        parent::__construct();
        $this->objectUser = &$user;
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
    public static function get(ObjectUser &$user): self
    {
        $id = $user->id;

        if (!isset(self::$users[$id])) {
            self::$users[$id] = new self($user);
        } elseif (self::$users[$id]->objectUser !== $user) {
            // Object reference changed, recreate
            self::$users[$id] = new self($user);
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

        return $data;
    }

    /**
     * Example: Lazy load orders (implement when Order entity exists)
     * 
     * Usage: Idea::$idea->users[123]->orders[456]->amount
     * 
     * @return IdeaCollection Collection of Order ideas
     */
    // private function loadOrders(): IdeaCollection
    // {
    //     if ($this->hasRelatedCache('orders')) {
    //         return $this->getCachedRelated('orders');
    //     }
    //
    //     // Load orders from database using Entity
    //     $entityOrders = EntityOrder::get(['user_id' => $this->objectUser->id]);
    //     
    //     // Create Object collection
    //     $objectOrders = ObjectOrders::initEmpty();
    //     foreach ($entityOrders as $entityOrder) {
    //         $objectOrder = ObjectOrder::fromEntity($entityOrder);
    //         $objectOrders[$objectOrder->id] = $objectOrder;
    //     }
    //
    //     // Create Idea collection from Object collection
    //     $ideaOrders = IdeaOrders::init($objectOrders);
    //     $this->setCachedRelated('orders', $ideaOrders);
    //
    //     return $ideaOrders;
    // }

    /**
     * Example: Lazy load posts (implement when Post entity exists)
     * 
     * @return IdeaCollection Collection of Post ideas
     */
    // private function loadPosts(): IdeaCollection
    // {
    //     if ($this->hasRelatedCache('posts')) {
    //         return $this->getCachedRelated('posts');
    //     }
    //
    //     // Load posts from database
    //     $entityPosts = EntityPost::get(['user_id' => $this->objectUser->id]);
    //     
    //     // Create Object collection
    //     $objectPosts = ObjectPosts::initEmpty();
    //     foreach ($entityPosts as $entityPost) {
    //         $objectPost = ObjectPost::fromEntity($entityPost);
    //         $objectPosts[$objectPost->id] = $objectPost;
    //     }
    //
    //     // Create Idea collection from Object collection
    //     $ideaPosts = IdeaPosts::init($objectPosts);
    //     $this->setCachedRelated('posts', $ideaPosts);
    //
    //     return $ideaPosts;
    // }
}
