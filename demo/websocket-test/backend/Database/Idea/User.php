<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Hilos\Database\Idea\Idea as BaseIdea;
use Hilos\Database\Idea\IdeaCollection;

/**
 * User Idea
 * High-level abstraction with lazy loading and relationships
 *
 * @property-read int $id
 * @property-read string $name
 * @property-read ?string $theme
 * @property-read bool $admin
 * @property-read bool $block
 * @property-read ?int $willDelete
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
            ObjectUser::admin => $this->objectUser->admin,
            ObjectUser::block => $this->objectUser->block,
            ObjectUser::willDelete => $this->objectUser->willDelete,

            // Example of lazy loading (implement when you have related entities)
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
        $data[ObjectUser::admin] = $this->objectUser->admin;
        $data[ObjectUser::block] = $this->objectUser->block;
        $data[ObjectUser::willDelete] = $this->objectUser->willDelete;

        return $data;
    }

    /**
     * Example: Lazy load posts (implement when Post entity exists)
     */
    // private function loadPosts(): array
    // {
    //     if ($this->hasRelatedCache('posts')) {
    //         return $this->getCachedRelated('posts');
    //     }
    //
    //     // Load posts from database
    //     $posts = Post::getByUserId($this->objectUser->id);
    //     $this->setCachedRelated('posts', $posts);
    //
    //     return $posts;
    // }
}
