<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminCategoriesPage;
use Demo\EcommerceShop\Pages\DTO\CategoryPicturePublishActionDTO;
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
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
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

    /** @var object{published: list<array{string, string, list<string>, string}>} The swapped door and the publications it wrote down */
    protected object $recorder;

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
}

/**
 * The categories admin page in the admin view mode: a guest and a signed-in person without admin rights
 * see every field of a category in the first answer, an administrator sees no mark at all, and with the
 * mode off the page is closed to whoever is not an administrator.
 *
 * The category is written through its library, its picture is a registry row with no file behind it, and
 * the two people are rows written straight into their table. The rollback scope proves reads of written rows.
 *
 * An administrator also adds a category: the picture is published through the files door, swapped here for
 * one that writes each publication down instead of sending it, and the answer is delivered to the library
 * by hand; the add checks the picture is a fresh file of the administrator.
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
    private int $visitorId;
    private int $adminId;

    /** The library the actions and the answers of the case go through, one instance for the whole case. */
    private CategoriesLibraryAgent $library;

    /** On an empty catalog, one category of the case, and a guest, a person without admin rights and an administrator connected. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $this->pictureFileId = self::pictureFile();
        $this->categoryName = 'Category ' . RandomHelper::hex(8);
        $category = Hilos::$db->categories->actions->create($this->categoryName, 'Flowers of the case', $this->pictureFileId);
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
     * An administrator's picture goes to the files door at once, and the action waits for the answer: the
     * publication answer acknowledges it with the file and the address of its modal preview copy.
     */
    public function testAPublishedPictureAcknowledgesTheActionWithItsFileAndPreview(): void
    {
        $this->dispatchAction(self::ADMIN_KEY, EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH, [
            CategoryPicturePublishActionDTO::clientUploadId => 'upload-roses',
        ], 'req-picture-publish');

        self::assertSame(
            [[self::ADMIN_KEY, 'catalog_picture', ['upload-roses'], 'category_picture_published']],
            $this->recorder->published,
        );
        self::assertSame([], $this->actionFrames(), 'The action is not acknowledged before the files door answers');

        $fileId = $this->ownPictureFile($this->adminId, false);
        $this->answer(new FilesPublishedSignalData(self::ADMIN_KEY, ['upload-roses'], [$fileId], null));

        $success = $this->acknowledgement(SignalConstants::ACTION_SUCCESS, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame('req-picture-publish', $success->requestId);
        self::assertSame(EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH, $success->action);
        self::assertSame([
            'fileId' => $fileId,
            'picture' => HilosFiles::downloadPath($fileId, 'catalog_sm'),
        ], $success->reply);
    }

    /** A publication the files door refuses fails the action with the door's sentence. */
    public function testARefusedPublicationFailsTheActionWithItsSentence(): void
    {
        $this->dispatchAction(self::ADMIN_KEY, EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH, [
            CategoryPicturePublishActionDTO::clientUploadId => 'upload-tulips',
        ], 'req-picture-refused');
        self::assertSame([], $this->actionFrames());

        $this->answer(FilesPublishedSignalData::refused(self::ADMIN_KEY, ['upload-tulips'], 'This file has not finished uploading'));

        $error = $this->acknowledgement(SignalConstants::ACTION_ERROR, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame('req-picture-refused', $error->requestId);
        self::assertSame(EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH, $error->action);
        self::assertSame('This file has not finished uploading', $error->reason);
    }

    /** An answer nobody waits on - a repeat, or one for an upload never asked here - sends no frame at all. */
    public function testAnAnswerNobodyWaitsOnSendsNoFrame(): void
    {
        $fileId = $this->ownPictureFile($this->adminId, false);
        $this->answer(new FilesPublishedSignalData(self::ADMIN_KEY, ['upload-never-asked'], [$fileId], null));

        self::assertSame([], $this->actionFrames());
    }

    /** An administrator adds a category with a picture just published: it is stored last in the catalog, and the success names it. */
    public function testAnAdminAddsACategoryLastInTheCatalog(): void
    {
        $name = 'Category ' . RandomHelper::hex(8);
        $fileId = $this->ownPictureFile($this->adminId, false);
        $this->dispatchAction(self::ADMIN_KEY, EcommerceShopSignalConstants::CATEGORY_CREATE, [
            ObjectCategory::name => $name,
            ObjectCategory::description => 'Lilies of the case',
            ObjectCategory::pictureFileId => $fileId,
        ], 'req-category-create');

        $success = $this->acknowledgement(SignalConstants::ACTION_SUCCESS, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame('req-category-create', $success->requestId);
        self::assertSame(EcommerceShopSignalConstants::CATEGORY_CREATE, $success->action);
        self::assertSame(sprintf('Category “%s” added.', $name), $success->message);
        $stored = Database::sql('SELECT id, description, picture_file_id FROM category WHERE name = ?', [$name])->rows();
        self::assertCount(1, $stored);
        self::assertSame('Lilies of the case', $stored[0]['description']);
        self::assertSame($fileId, (int)$stored[0]['picture_file_id']);
        self::assertSame(self::storedHighestPosition(), self::storedPosition((int)$stored[0]['id']));
        self::assertGreaterThan($this->categoryPosition, self::storedPosition((int)$stored[0]['id']));
    }

    /**
     * A picture that is not a fresh file of the administrator - somebody else's, already shown by a record,
     * or no registry row at all - is refused, and no category is written.
     */
    public function testAPictureThatIsNotAFreshFileOfTheAdminIsRefused(): void
    {
        $pictures = [
            'foreign' => $this->ownPictureFile($this->visitorId, false),
            'bound' => $this->ownPictureFile($this->adminId, true),
            'unknown' => 999_999_999,
        ];
        foreach ($pictures as $case => $fileId) {
            $name = 'Category ' . RandomHelper::hex(8);
            $this->dispatchAction(self::ADMIN_KEY, EcommerceShopSignalConstants::CATEGORY_CREATE, [
                ObjectCategory::name => $name,
                ObjectCategory::description => 'Lilies of the case',
                ObjectCategory::pictureFileId => $fileId,
            ], "req-category-{$case}");

            $error = $this->acknowledgement(SignalConstants::ACTION_ERROR, self::ADMIN_KEY);
            self::assertInstanceOf(PageActionErrorSignalData::class, $error);
            self::assertSame("req-category-{$case}", $error->requestId);
            self::assertSame(EcommerceShopSignalConstants::CATEGORY_CREATE, $error->action);
            self::assertSame('This file is gone; upload it again', $error->reason, $case);
            self::assertSame(0, self::storedCountNamed($name), $case);
        }
    }

    /** A name another category already carries is refused with the library's sentence, and no second row is written. */
    public function testAnAdminIsRefusedATakenCategoryName(): void
    {
        $this->dispatchAction(self::ADMIN_KEY, EcommerceShopSignalConstants::CATEGORY_CREATE, [
            ObjectCategory::name => $this->categoryName,
            ObjectCategory::description => 'Lilies of the case',
            ObjectCategory::pictureFileId => $this->ownPictureFile($this->adminId, false),
        ], 'req-category-taken');

        $error = $this->acknowledgement(SignalConstants::ACTION_ERROR, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame('req-category-taken', $error->requestId);
        self::assertSame('A category with this name already exists', $error->reason);
        self::assertSame(1, self::storedCountNamed($this->categoryName));
    }

    /** With the admin view mode on, the picture publish and the add are refused to a guest and a non-admin viewer. */
    public function testAGuestAndANonAdminAreRefusedThePublishAndTheAddWhenTheViewModeIsOn(): void
    {
        foreach ([EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH, EcommerceShopSignalConstants::CATEGORY_CREATE] as $action) {
            foreach ([self::GUEST_KEY, self::VISITOR_KEY] as $acceptKey) {
                try {
                    PageAccessGate::assertAction(AdminCategoriesPage::class, $acceptKey, $action);
                    self::fail("Expected ActionViewModeException for {$acceptKey} on {$action}");
                } catch (ActionViewModeException) {
                    // Expected
                }
            }
        }
        self::assertSame([], $this->recorder->published);
    }

    /** The test-only command removes the category of the given name, and answers a repeat that nothing was removed. */
    public function testTheTestCommandDeletesACategoryByItsName(): void
    {
        self::assertSame([EcommerceShopCommandConstants::deleted => true], $this->command($this->categoryName)->payload);
        self::assertSame(0, self::storedCountNamed($this->categoryName));
        self::assertSame([EcommerceShopCommandConstants::deleted => false], $this->command($this->categoryName)->payload);
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
     * Writes a registry row published by the given person, with no file on disk behind it.
     *
     * @param int $ownerUserId Person who published the file
     * @param bool $bound Whether a record already shows the file
     * @return int Id of the new registry row
     */
    private function ownPictureFile(int $ownerUserId, bool $bound): int
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
     * Delivers a publication answer of the files door to the case's library under its frame.
     *
     * @param FilesPublishedSignalData $answer Answer of the files door
     */
    private function answer(FilesPublishedSignalData $answer): void
    {
        $library = $this->library;
        ExecutionContext::run(
            new ExecutionFrame(agentId: $library->getId()),
            static fn () => $library->onSignalAgent(
                new AgentSignalData($answer),
                'agent',
                EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISHED,
            ),
        );
    }

    /**
     * Drains the queue and keeps the action acknowledgements in it.
     *
     * @return list<SignalDataInterface> Action success and error payloads, in queue order
     */
    private function actionFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (in_array($signal->signalName->getName(), [SignalConstants::ACTION_SUCCESS, SignalConstants::ACTION_ERROR], true)) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                $frames[] = $signal->data->data;
            }
        }

        return $frames;
    }

    /**
     * Delivers the test-only delete command to the categories library under its frame.
     *
     * @param string $name Name of the category to remove
     * @return CommandReplyDTO The single successful reply
     */
    private function command(string $name): CommandReplyDTO
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $library = $this->library;
        $command = EcommerceShopCommandConstants::CATEGORY_TEST_DELETE;
        $request = Hilos::$sr->createCommandPayloadDTO(
            $command,
            new CommandRequestDTO('category-delete', $command, [EcommerceShopCommandConstants::name => $name]),
        );
        ExecutionContext::run(
            new ExecutionFrame(agentId: $library->getId()),
            static fn () => $library->onSignalCommand($request, 'command', $command),
        );
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        self::assertCount(1, $replies, 'The command answers exactly once');
        self::assertSame('category-delete', $replies[0]->correlationId);
        self::assertTrue($replies[0]->isOk());

        return $replies[0];
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

    /**
     * @param string $name Category name
     * @return int Number of stored categories of that name, read past the ORM
     */
    private static function storedCountNamed(string $name): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS n FROM category WHERE name = ?', [$name])->field('n');
    }

    /**
     * @return int The highest stored position among all categories, read past the ORM
     */
    private static function storedHighestPosition(): int
    {
        return (int)Database::sql('SELECT MAX(position) AS max_pos FROM category')->field('max_pos');
    }
}
