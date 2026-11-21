<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminCategoriesPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Tables\Category\CategoriesTable;
use Demo\EcommerceShop\Tables\Category\CategoryTableRow;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
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
    private const string TEST_AGENT = 'admin-categories-page-test';

    private ?SignalRouter $previousRouter = null;
    private bool $claimed = false;

    /**
     * Starts the categories library beneath and forgets what the ORM loaded earlier in the process, then
     * mounts the browser, the router, the connections claim and the view mode.
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
    }

    /**
     * Turns the view mode off and forgets the connections if they were claimed, restores the router and
     * the browser, then releases beneath, in every outcome of the preparation.
     */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
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
}

/**
 * The categories admin page in the admin view mode: a guest and a signed-in person without admin rights
 * see every field of a category in the first answer, an administrator sees no mark at all, and with the
 * mode off the page is closed to whoever is not an administrator.
 *
 * The category is written through its library, its picture is a registry row with no file behind it, and
 * the two people are rows written straight into their table. The rollback scope proves reads of written rows.
 */
#[IntegrationTest]
final class AdminCategoriesPageTest extends AdminCategoriesPageEnvironment
{
    private const string GUEST_KEY = 'admin-categories-guest';
    private const string VISITOR_KEY = 'admin-categories-visitor';
    private const string ADMIN_KEY = 'admin-categories-admin';

    private int $categoryId;
    private int $categoryPosition;
    private string $categoryName;
    private int $pictureFileId;

    /** On an empty catalog, one category of the case, and a guest, a person without admin rights and an administrator connected. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $this->pictureFileId = self::pictureFile();
        $this->categoryName = 'Category ' . RandomHelper::hex(8);
        $category = Hilos::$db->categories->actions->create($this->categoryName, 'Flowers of the case', $this->pictureFileId);
        $this->categoryId = $category->id;
        $this->categoryPosition = $category->position;
        $visitorId = self::person('Categories visitor', false);
        $adminId = self::person('Categories admin', true);
        self::unattended(static function () use ($visitorId, $adminId): void {
            Hilos::$rt->connections->actions->register(self::GUEST_KEY, null);
            Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
            Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);
        });
    }

    /** A viewer receives the categories window in the first answer, and the case's category with all seven fields shown. */
    public function testAViewerSeesTheCategoryWithEveryFieldShown(): void
    {
        foreach ([self::GUEST_KEY, self::VISITOR_KEY] as $acceptKey) {
            $rows = $this->window($this->subscribe($acceptKey))[TableWindowSignalData::rows];
            $slot = null;
            foreach ($rows as $row) {
                $candidate = $row[PagePayload::slots][CategoriesTable::ROW_SLOT];
                if ($candidate[CategoryTableRow::id] === $this->categoryId) {
                    $slot = $candidate;
                    break;
                }
            }
            self::assertNotNull($slot, "Category row not found for {$acceptKey}");
            self::assertSame([
                CategoryTableRow::id => $this->categoryId,
                CategoryTableRow::name => $this->categoryName,
                CategoryTableRow::description => 'Flowers of the case',
                CategoryTableRow::position => $this->categoryPosition,
                CategoryTableRow::picture => HilosFiles::downloadPath($this->pictureFileId, CatalogPictureUploadTarget::VARIANT_XS),
                CategoryTableRow::productCount => 0,
                CategoryTableRow::first => $this->categoryPosition === self::storedLowestPosition(),
            ], $slot);
            foreach ($slot as $field => $value) {
                self::assertFalse(HiddenValue::isMark($value), "field {$field} is a mark for {$acceptKey}");
            }
        }
    }

    /** An administrator is sent the page without a single mark. */
    public function testAnAdminIsSentThePageWithoutASingleMark(): void
    {
        $payloads = $this->subscribe(self::ADMIN_KEY);

        self::assertArrayHasKey(EcommerceShopTableContext::categories, $payloads[0][PageResponseSignalData::payload][PagePayload::windows]);
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode($payloads, JSON_THROW_ON_ERROR));
    }

    /** With the view mode off the page is closed to a person without admin rights. */
    public function testANonAdminIsRefusedWhenTheViewModeIsOff(): void
    {
        self::unattended(static function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        });
        $this->expectException(PageForbiddenException::class);
        PageAccessGate::assert(AdminCategoriesPage::class, self::VISITOR_KEY);
    }

    /** An administrator moves the second category up, swapping positions in database and receiving success acknowledgement. */
    public function testAnAdminCanMoveACategoryUp(): void
    {
        $cat2Name = 'Category ' . RandomHelper::hex(8);
        $cat2 = Hilos::$db->categories->actions->create($cat2Name, 'Second flowers of the case', self::pictureFile());
        $cat2Id = $cat2->id;

        $pos1Before = self::storedPosition($this->categoryId);
        $pos2Before = self::storedPosition($cat2Id);
        self::assertLessThan($pos2Before, $pos1Before);

        $this->dispatchAction(self::ADMIN_KEY, EcommerceShopSignalConstants::CATEGORY_MOVE_UP, [
            ObjectCategory::id => $cat2Id,
        ], 'req-move-up-success');

        $success = $this->acknowledgement(SignalConstants::ACTION_SUCCESS, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame('req-move-up-success', $success->requestId);
        self::assertSame(EcommerceShopSignalConstants::CATEGORY_MOVE_UP, $success->action);
        self::assertSame(sprintf('Category “%s” moved up.', $cat2Name), $success->message);

        self::assertSame($pos2Before, self::storedPosition($this->categoryId));
        self::assertSame($pos1Before, self::storedPosition($cat2Id));
    }

    /** Moving the first category in the catalog up produces an action error and leaves positions unchanged. */
    public function testMovingTheFirstCategoryUpIsRefused(): void
    {
        $firstId = $this->categoryId;
        while (true) {
            try {
                Hilos::$db->categories[$firstId]->actions->moveUp();
            } catch (ValidationException) {
                break;
            }
        }

        $posBefore = self::storedPosition($firstId);
        $this->dispatchAction(self::ADMIN_KEY, EcommerceShopSignalConstants::CATEGORY_MOVE_UP, [
            ObjectCategory::id => $firstId,
        ], 'req-move-up-first');

        $error = $this->acknowledgement(SignalConstants::ACTION_ERROR, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame('req-move-up-first', $error->requestId);
        self::assertSame(EcommerceShopSignalConstants::CATEGORY_MOVE_UP, $error->action);
        self::assertSame('This category is already first', $error->reason);
        self::assertSame($posBefore, self::storedPosition($firstId));
    }

    /** Moving a non-existent category up produces an action error stating it no longer exists. */
    public function testMovingANonExistentCategoryUpIsRefused(): void
    {
        $nonExistentId = 999_999;
        $this->dispatchAction(self::ADMIN_KEY, EcommerceShopSignalConstants::CATEGORY_MOVE_UP, [
            ObjectCategory::id => $nonExistentId,
        ], 'req-move-up-not-found');

        $error = $this->acknowledgement(SignalConstants::ACTION_ERROR, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame('req-move-up-not-found', $error->requestId);
        self::assertSame(EcommerceShopSignalConstants::CATEGORY_MOVE_UP, $error->action);
        self::assertSame('This category no longer exists', $error->reason);
    }

    /** With the admin view mode on, move up action is refused to a guest and a non-admin viewer with ActionViewModeException. */
    public function testAGuestAndANonAdminAreRefusedWhenTheViewModeIsOn(): void
    {
        $posBefore = self::storedPosition($this->categoryId);
        foreach ([self::GUEST_KEY, self::VISITOR_KEY] as $acceptKey) {
            try {
                PageAccessGate::assertAction(
                    AdminCategoriesPage::class,
                    $acceptKey,
                    EcommerceShopSignalConstants::CATEGORY_MOVE_UP,
                );
                self::fail("Expected ActionViewModeException for {$acceptKey}");
            } catch (ActionViewModeException) {
                // Expected
            }
        }
        self::assertSame($posBefore, self::storedPosition($this->categoryId));
    }

    /**
     * Writes a registry row a category may show, with no file on disk behind it.
     *
     * @return int Id of the new registry row
     */
    private static function pictureFile(): int
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

    /**
     * Subscribes one connection with an open categories window and reads the one page answer.
     *
     * @param string $acceptKey Subscribing connection
     * @return list<array<string, mixed>> Wire arrays of the page answers, exactly one
     */
    private function subscribe(string $acceptKey): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $windows = [EcommerceShopTableContext::categories => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)];
        Hilos::$sr->subscribeToPage(
            AdminCategoriesPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, AdminCategoriesPage::PAGE, [], $windows),
        );
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey): void {
            new AdminCategoriesPage(new CategoriesLibraryAgent())->onSubscribe($acceptKey, new PageRouteParams([]));
        });

        $payloads = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
                $payloads[] = $signal->data->data->toArray();
            }
        }
        self::assertCount(1, $payloads);

        return $payloads;
    }

    /**
     * Reads the categories window out of the page answers.
     *
     * @param list<array<string, mixed>> $payloads Wire arrays of the page answers
     * @return array<string, mixed> The window of the categories table
     */
    private function window(array $payloads): array
    {
        return $payloads[0][PageResponseSignalData::payload][PagePayload::windows][EcommerceShopTableContext::categories];
    }

    /**
     * Sends one action frame through the real page router under the library agent.
     *
     * @param string $acceptKey Acting connection
     * @param string $action Action name
     * @param array<string, mixed> $payload Action payload
     * @param ?string $requestId Client-minted request id
     */
    private function dispatchAction(string $acceptKey, string $action, array $payload, ?string $requestId = null): void
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $agent = new CategoriesLibraryAgent();
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
    private function acknowledgement(string $name, string $acceptKey): SignalDataInterface
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
     * @param int $id Category id
     * @return int The stored place of the category in the catalog, read past the ORM
     */
    private static function storedPosition(int $id): int
    {
        return (int)Database::sql('SELECT position FROM category WHERE id = ?', [$id])->field('position');
    }

    /**
     * @return ?int The lowest stored position among all categories, read past the ORM
     */
    private static function storedLowestPosition(): ?int
    {
        $val = Database::sql('SELECT MIN(position) AS min_pos FROM category')->field('min_pos');

        return $val === null ? null : (int)$val;
    }
}
