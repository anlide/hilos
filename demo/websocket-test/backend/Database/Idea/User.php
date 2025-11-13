<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Hilos\Database\Idea\Idea as BaseIdea;

/**
 * User Idea
 * High-level abstraction with lazy loading and relationships
 * 
 * @property-read int $idUser
 * @property-read string $email
 * @property-read string $name
 * @property-read ?string $theme
 * @property-read ?int $idLanguage
 * @property-read ?int $idLocale
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
        $id = $user->idUser;

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
    public function __get(string $property): mixed
    {
        return match ($property) {
            ObjectUser::idUser => $this->objectUser->idUser,
            ObjectUser::email => $this->objectUser->email,
            ObjectUser::name => $this->objectUser->name,
            ObjectUser::theme => $this->objectUser->theme,
            ObjectUser::idLanguage => $this->objectUser->idLanguage,
            ObjectUser::idLocale => $this->objectUser->idLocale,
            ObjectUser::admin => $this->objectUser->admin,
            ObjectUser::block => $this->objectUser->block,
            ObjectUser::willDelete => $this->objectUser->willDelete,
            
            // Example of lazy loading (implement when you have related entities)
            // 'posts' => $this->loadPosts(),
            // 'language' => $this->loadLanguage(),
            // 'locale' => $this->loadLocale(),
            
            default => throw new \Exception("Property [{$property}] does not exist on IdeaUser"),
        };
    }

    /**
     * Convert to array
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectUser::idUser] = $this->objectUser->idUser;
        }

        $data[ObjectUser::email] = $this->objectUser->email;
        $data[ObjectUser::name] = $this->objectUser->name;
        $data[ObjectUser::theme] = $this->objectUser->theme;
        $data[ObjectUser::idLanguage] = $this->objectUser->idLanguage;
        $data[ObjectUser::idLocale] = $this->objectUser->idLocale;
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
    //     $posts = Post::getByUserId($this->objectUser->idUser);
    //     $this->setCachedRelated('posts', $posts);
    //     
    //     return $posts;
    // }
}

