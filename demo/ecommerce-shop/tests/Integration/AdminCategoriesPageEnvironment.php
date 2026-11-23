<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CategoryUpdateActionDTO;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Mounts the shop's browser and its own signal router over the categories environment, turns the admin
 * view mode on, and claims the connections register the page gate reads a subscriber's identity from.
 *
 * The runtime writes run outside any agent, as the daemon's do: the environment beneath runs under the
 * categories library's frame, which owns neither the connections nor the view mode.
 */
abstract class AdminCategoriesPageEnvironment extends CategoriesLibraryEnvironment
{
    use CatalogFixtureRows;

    protected const string GUEST_KEY = 'admin-categories-guest';
    protected const string VISITOR_KEY = 'admin-categories-visitor';
    protected const string ADMIN_KEY = 'admin-categories-admin';
    protected const string CATEGORY_DESCRIPTION = 'Flowers of the case';

    private const string TEST_AGENT = 'admin-categories-page-test';

    /** @var object{published: list<array{string, string, list<string>, string}>} The swapped door and the publications it wrote down */
    protected object $recorder;

    protected int $categoryId;
    protected int $categoryPosition;
    protected string $categoryName;
    protected int $pictureFileId;
    protected int $visitorId;
    protected int $adminId;

    /** The library the actions and the answers of the case go through, one instance for the whole case. */
    protected CategoriesLibraryAgent $library;

    private ?SignalRouter $previousRouter = null;
    private bool $claimed = false;
    private ?HilosFiles $door = null;

    /**
     * Starts the categories library beneath and forgets what the ORM loaded earlier in the process, then
     * mounts the browser, the router, the connections claim and the view mode, and swaps the files door
     * for one that writes each publication down instead of sending it.
     *
     * The people are rows written past the users library, so a users collection an earlier case warmed
     * would read them as absent; the next read reads the table again, inside the case, and the rollback
     * forgets that read with the rows.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        parent::prepareIntegrationEnvironment();
        Hilos::$db->forgetLoadedDataForTest();
        Hilos::initBrowser();
        Hilos::$sr = new EcommerceShopSignalRouter();
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->claimed = true;
        self::unattended(static function (): void {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        });
        $door = Hilos::$files;
        self::assertNotNull($door);
        $this->door = $door;
        $this->recorder = new class ($door->storage) extends HilosFiles {
            /** @var list<array{string, string, list<string>, string}> Each publication: connection, target, uploads, answer name */
            public array $published = [];

            /**
             * @param string $acceptKey Connection the uploads belong to
             * @param string $target Upload target
             * @param list<string> $clientUploadIds Uploads to publish
             * @param string $replySignal Name the answer comes under
             */
            public function publishUploads(string $acceptKey, string $target, array $clientUploadIds, string $replySignal): void
            {
                $this->published[] = [$acceptKey, $target, $clientUploadIds, $replySignal];
            }
        };
        Hilos::$files = $this->recorder;
    }

    /** On an empty catalog, one category of the case, and a guest, a person without admin rights and an administrator connected. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $this->pictureFileId = self::pictureFile();
        $this->categoryName = 'Category ' . RandomHelper::hex(8);
        $category = Hilos::$db->categories->actions->create($this->categoryName, self::CATEGORY_DESCRIPTION, $this->pictureFileId);
        $this->categoryId = $category->id;
        $this->categoryPosition = $category->position;
        $this->library = new CategoriesLibraryAgent();
        $visitorId = self::person('Categories visitor', false);
        $adminId = self::person('Categories admin', true);
        $this->visitorId = $visitorId;
        $this->adminId = $adminId;
        self::unattended(static function () use ($visitorId, $adminId): void {
            Hilos::$rt->connections->actions->register(self::GUEST_KEY, null);
            Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
            Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);
        });
    }

    /**
     * Puts the files door back, turns the view mode off and forgets the connections if they were claimed,
     * restores the router and the browser, then releases beneath, in every outcome of the preparation.
     */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            if ($this->door !== null) {
                Hilos::$files = $this->door;
                $this->door = null;
            }
            if ($this->claimed) {
                $this->claimed = false;
                self::unattended(static function (): void {
                    Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
                    Hilos::$rt->connections->actions->clear();
                });
                RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
                RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
            }
            Hilos::$sr = $this->previousRouter;
            Hilos::initBrowser();
        } finally {
            parent::releaseIntegrationEnvironment();
        }
    }

    /**
     * Runs a runtime write outside any agent, the way the daemon writes.
     *
     * @param callable(): void $write The write
     */
    protected static function unattended(callable $write): void
    {
        ExecutionContext::run(new ExecutionFrame(), $write);
    }

    /**
     * Sends one action frame through the real page router under the library agent.
     *
     * @param string $acceptKey Acting connection
     * @param string $action Action name
     * @param array<string, mixed> $payload Action payload
     * @param ?string $requestId Client-minted request id
     */
    protected function dispatchAction(string $acceptKey, string $action, array $payload, ?string $requestId = null): void
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $agent = $this->library;
        $router = new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig(Hilos::getPageActionRoutes()),
        );

        ExecutionContext::run(
            new ExecutionFrame(agentId: $agent->getId(), acceptKey: $acceptKey),
            static fn () => $router->dispatchAction(
                new WebSocketActionSignalDTO($acceptKey, $action, $payload, $requestId),
                'websocket',
            ),
        );
    }

    /**
     * Reads one acknowledgement signal of the given name.
     *
     * @param string $name Expected signal name
     * @param string $acceptKey Expected target accept key
     * @return SignalDataInterface Signal payload data
     */
    protected function acknowledgement(string $name, string $acceptKey): SignalDataInterface
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame($acceptKey, $signal->data->targetAcceptKey);
                $frames[] = $signal->data->data;
            }
        }
        self::assertCount(1, $frames);

        return $frames[0];
    }

    /**
     * @param array<string, mixed> $fields Fields to change in the default edit request
     * @return array<string, mixed> Edit request with the baseline of the fixture
     */
    protected function updatePayload(array $fields = []): array
    {
        return array_replace([
            ObjectCategory::id => $this->categoryId,
            ObjectCategory::name => $this->categoryName . ' revised',
            ObjectCategory::description => 'Fresh cut lilies',
            ObjectCategory::pictureFileId => null,
            CategoryUpdateActionDTO::expectedName => $this->categoryName,
            CategoryUpdateActionDTO::expectedDescription => self::CATEGORY_DESCRIPTION,
            CategoryUpdateActionDTO::expectedPictureFileId => $this->pictureFileId,
        ], $fields);
    }

    /**
     * @param array<string, mixed> $payload Edit request
     * @param string $acceptKey Acting connection
     * @return PageActionErrorSignalData The tracked refusal of the update
     */
    protected function refusedUpdate(array $payload, string $acceptKey = self::ADMIN_KEY): PageActionErrorSignalData
    {
        $this->dispatchAction($acceptKey, EcommerceShopSignalConstants::CATEGORY_UPDATE, $payload, 'req-category-update');

        $error = $this->acknowledgement(SignalConstants::ACTION_ERROR, $acceptKey);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame('req-category-update', $error->requestId);
        self::assertSame(EcommerceShopSignalConstants::CATEGORY_UPDATE, $error->action);

        return $error;
    }

    /**
     * @param array<string, mixed> $payload Delete request
     * @param string $acceptKey Acting connection
     * @return PageActionErrorSignalData The tracked refusal of the delete
     */
    protected function refusedDelete(array $payload, string $acceptKey = self::ADMIN_KEY): PageActionErrorSignalData
    {
        $this->dispatchAction($acceptKey, EcommerceShopSignalConstants::CATEGORY_DELETE, $payload, 'req-category-delete');

        $error = $this->acknowledgement(SignalConstants::ACTION_ERROR, $acceptKey);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame('req-category-delete', $error->requestId);
        self::assertSame(EcommerceShopSignalConstants::CATEGORY_DELETE, $error->action);

        return $error;
    }

    /** @return array{string, string, int} Persisted category name, description and picture file ID */
    protected function storedCategory(): array
    {
        $row = Database::sql('SELECT name, description, picture_file_id FROM category WHERE id = ?', [$this->categoryId])->row();
        self::assertNotNull($row);

        return [$row[EntityCategory::name], $row[EntityCategory::description], (int)$row[EntityCategory::picture_file_id]];
    }

    /**
     * Writes a registry row a category may show, with no file on disk behind it.
     *
     * @return int Id of the new registry row
     */
    protected static function pictureFile(): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, visibility, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$storedName, 'roses.webp', 'image/webp', 4, hash('sha256', $storedName), FileVisibility::PUBLIC->value, 1000],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a registry row published by the given person, with no file on disk behind it.
     *
     * @param int $ownerUserId Person who published the file
     * @param bool $bound Whether a record already shows the file
     * @return int Id of the new registry row
     */
    protected function ownPictureFile(int $ownerUserId, bool $bound): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, owner_user_id, visibility, bound, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $storedName, 'lilies.webp', 'image/webp', 4, hash('sha256', $storedName),
                $ownerUserId, FileVisibility::PUBLIC->value, (int)$bound, 1000,
            ],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a person past the users library and drops a verdict an earlier case of this process may have
     * remembered for the same id.
     *
     * @param string $name Name of the person
     * @param bool $admin Whether the person is an administrator
     * @return int Id of the new person
     */
    private static function person(string $name, bool $admin): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, ?, 0)', [$name, (int)$admin]);
        $userId = Database::lastInsertId();
        AccountStandingResolver::forget($userId);

        return $userId;
    }
}
