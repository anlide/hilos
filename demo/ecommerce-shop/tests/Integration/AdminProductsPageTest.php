<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\Object\Item\ProductSlug as ObjectProductSlug;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminProductsPage;
use Demo\EcommerceShop\Pages\DTO\ProductPicturePublishActionDTO;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Demo\EcommerceShop\Tables\Product\ProductTableRow;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DbSyncApplicator;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/** The products page and its actions under the products library and the admin gate. */
#[IntegrationTest]
final class AdminProductsPageTest extends AdminProductsPageActionEnvironment
{
    private const string GUEST_KEY = 'admin-products-guest';

    private int $categoryPosition;
    private string $categoryName;
    private int $pictureFileId;
    private int $productId;
    private string $productName;

    /** On an empty catalog, one stock product without stock, and a guest, a person without admin rights and an administrator connected. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $this->categoryId = self::category();
        $category = Database::sql('SELECT * FROM category WHERE id = ?', [$this->categoryId])->rows()[0];
        $this->categoryName = $category['name'];
        $this->categoryPosition = (int)$category['position'];
        $this->productName = 'Product ' . RandomHelper::hex(8);
        $this->productId = self::productIn($this->categoryId, ProductKind::STOCK, $this->productName, 41000, 1795132800123);
        $this->pictureFileId = (int)Database::sql('SELECT picture_file_id FROM product WHERE id = ?', [$this->productId])
            ->field('picture_file_id');
        self::holdCatalog();
        self::unattended(static function (): void {
            Hilos::$rt->connections->actions->register(self::GUEST_KEY, null);
        });
        $this->connectProductPageUsers();
    }

    /** A viewer receives the product and category options together in the first answer, with every field shown. */
    public function testAViewerSeesTheProductAndCategoriesWithEveryFieldShown(): void
    {
        foreach ([self::GUEST_KEY, self::VISITOR_KEY] as $acceptKey) {
            $payload = $this->subscribe($acceptKey)[0][PageResponseSignalData::payload];
            $slot = self::slotFor(
                $payload[PagePayload::windows][EcommerceShopTableContext::products][TableWindowSignalData::rows],
                PagePayload::rowKey,
                $this->productId,
                'product',
            );
            self::assertSame([
                ProductTableRow::id => $this->productId,
                ProductTableRow::name => $this->productName,
                ProductTableRow::pictureUrl => HilosFiles::downloadPath($this->pictureFileId, CatalogPictureUploadTarget::VARIANT_XS),
                ProductTableRow::categoryId => $this->categoryId,
                ProductTableRow::categoryName => $this->categoryName,
                ProductTableRow::kind => 'stock',
                ProductTableRow::price => 41000,
                ProductTableRow::units => 0,
                ProductTableRow::warehouseName => null,
                ProductTableRow::availability => 'out_of_stock',
                ProductTableRow::changedAt => 1795132800123,
            ], $slot);
            $category = self::slotFor(
                $payload[PagePayload::lists][EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES][PagePayload::items],
                PagePayload::itemKey,
                $this->categoryId,
                EcommerceShopDbContext::categories,
            );
            self::assertSame([
                ObjectCategory::id => $this->categoryId,
                ObjectCategory::name => $this->categoryName,
                ObjectCategory::position => $this->categoryPosition,
            ], $category);
            foreach ([$slot, $category] as $fields) {
                foreach ($fields as $field => $value) {
                    self::assertFalse(HiddenValue::isMark($value), "field {$field} is a mark for {$acceptKey}");
                }
            }
        }
    }

    /** An administrator is sent the page without a single mark. */
    public function testAnAdminIsSentThePageWithoutASingleMark(): void
    {
        $payloads = $this->subscribe(self::ADMIN_KEY);

        self::assertArrayHasKey(EcommerceShopTableContext::products, $payloads[0][PageResponseSignalData::payload][PagePayload::windows]);
        self::assertArrayHasKey(
            EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES,
            $payloads[0][PageResponseSignalData::payload][PagePayload::lists],
        );
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode($payloads, JSON_THROW_ON_ERROR));
    }

    /** With the view mode off the page is closed to a person without admin rights. */
    public function testANonAdminIsRefusedWhenTheViewModeIsOff(): void
    {
        self::unattended(static function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        });
        $this->expectException(PageForbiddenException::class);
        PageAccessGate::assert(AdminProductsPage::class, self::VISITOR_KEY);
    }

    /** A category rename reaches the subscribed viewer as a list item update. */
    public function testACategoryRenameReachesTheSubscribedViewer(): void
    {
        $this->subscribe(self::GUEST_KEY);
        Database::sql('UPDATE category SET name = ? WHERE id = ?', ['Renamed category', $this->categoryId]);
        $row = Database::sql('SELECT * FROM category WHERE id = ?', [$this->categoryId])->rows()[0];
        // A worker applies DB sync to held rows before handing its fact to the browser.
        self::assertTrue(DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::categories,
            (string)$this->categoryId,
            $row,
        ))->accepted);
        Hilos::$browser->record(SourceChange::dbUpdated(EcommerceShopDbContext::categories, (string)$this->categoryId, $row));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());

        $updates = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE
                || !$signal->data instanceof WebSocketSignalData
                || $signal->data->targetAcceptKey !== self::GUEST_KEY
                || !$signal->data->data instanceof PageResponseSignalData
            ) {
                continue;
            }
            self::assertSame(AdminProductsPage::PAGE, $signal->data->data->pageKey);
            $payload = $signal->data->data->payload->toArray();
            if (isset($payload[PagePayload::lists][EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES])) {
                $updates[] = $payload[PagePayload::lists][EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES];
            }
        }
        self::assertCount(1, $updates);
        self::assertSame([
            ObjectCategory::id => $this->categoryId,
            ObjectCategory::name => 'Renamed category',
            ObjectCategory::position => $this->categoryPosition,
        ], self::slotFor($updates[0][PagePayload::items], PagePayload::itemKey, $this->categoryId, EcommerceShopDbContext::categories));
    }

    /** Two outstanding picture publications acknowledge the right requests even when answered in reverse order. */
    public function testPicturePublicationAcknowledgesSuccessAndRefusalByRequestId(): void
    {
        $action = EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH;
        $this->dispatchAction($action, [ProductPicturePublishActionDTO::clientUploadId => 'upload-vase'], 'req-vase');
        $this->dispatchAction($action, [ProductPicturePublishActionDTO::clientUploadId => 'upload-pot'], 'req-pot');
        self::assertSame([
            [self::ADMIN_KEY, 'catalog_picture', ['upload-vase'], 'product_picture_published'],
            [self::ADMIN_KEY, 'catalog_picture', ['upload-pot'], 'product_picture_published'],
        ], $this->recorder->published);
        self::assertSame([], $this->actionFrames(self::ADMIN_KEY));

        $this->answer(FilesPublishedSignalData::refused(self::ADMIN_KEY, ['upload-pot'], 'This file has not finished uploading'));
        [$error] = $this->actionFrames(self::ADMIN_KEY, 1);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame('req-pot', $error->requestId);
        self::assertSame($action, $error->action);
        self::assertSame('This file has not finished uploading', $error->reason);

        $fileId = self::ownPictureFile($this->adminId);
        $this->answer(new FilesPublishedSignalData(self::ADMIN_KEY, ['upload-vase'], [$fileId], null));
        [$success] = $this->actionFrames(self::ADMIN_KEY, 1);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame('req-vase', $success->requestId);
        self::assertSame($action, $success->action);
        self::assertSame([
            'fileId' => $fileId,
            'picture' => HilosFiles::downloadPath($fileId, CatalogPictureUploadTarget::VARIANT_SM),
        ], $success->reply);
    }

    /** @param ?string $firstRequestId Tracked correlation or an untracked first request */
    #[DataProvider('publicationCorrelations')]
    public function testRepeatedPublicationKeepsTheFirstRequest(?string $firstRequestId): void
    {
        $action = EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH;
        $payload = [ProductPicturePublishActionDTO::clientUploadId => 'upload-vase'];
        $this->dispatchAction($action, $payload, $firstRequestId);
        $this->dispatchAction($action, $payload, $firstRequestId);
        self::assertSame([], $this->actionFrames(self::ADMIN_KEY));

        $this->dispatchAction($action, $payload, 'req-second');
        [$refusal] = $this->actionFrames(self::ADMIN_KEY, 1);
        self::assertInstanceOf(PageActionErrorSignalData::class, $refusal);
        self::assertSame('req-second', $refusal->requestId);
        self::assertSame($action, $refusal->action);
        self::assertSame('This picture is already being published', $refusal->reason);
        self::assertSame([
            [self::ADMIN_KEY, 'catalog_picture', ['upload-vase'], 'product_picture_published'],
        ], $this->recorder->published);

        $fileId = self::ownPictureFile($this->adminId);
        $this->answer(new FilesPublishedSignalData(self::ADMIN_KEY, ['upload-vase'], [$fileId], null));
        $frames = $this->actionFrames(self::ADMIN_KEY, $firstRequestId === null ? 0 : 1);
        if ($firstRequestId !== null) {
            [$success] = $frames;
            self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
            self::assertSame($firstRequestId, $success->requestId);
            self::assertSame($action, $success->action);
            self::assertSame([
                'fileId' => $fileId,
                'picture' => HilosFiles::downloadPath($fileId, CatalogPictureUploadTarget::VARIANT_SM),
            ], $success->reply);
        }

        $this->dispatchAction($action, $payload, 'req-retry');
        $this->answer(FilesPublishedSignalData::refused(self::ADMIN_KEY, ['upload-vase'], 'This file has not finished uploading'));
        [$retry] = $this->actionFrames(self::ADMIN_KEY, 1);
        self::assertInstanceOf(PageActionErrorSignalData::class, $retry);
        self::assertSame('req-retry', $retry->requestId);
        self::assertSame('This file has not finished uploading', $retry->reason);
    }

    /** @return array<string, array{?string}> */
    public static function publicationCorrelations(): array
    {
        return ['tracked first' => ['req-first'], 'untracked first' => [null]];
    }

    /**
     * An administrator creates each product kind with a price in kopecks and no warehouse rows.
     *
     * @param ProductKind $kind Product kind as chosen on the form
     * @param ProductAvailability $availability Initial availability shown on the products table
     */
    #[DataProvider('productKinds')]
    public function testAnAdminCreatesAProductWithoutStock(ProductKind $kind, ProductAvailability $availability): void
    {
        $input = $this->productInput('Blue vase');
        $input[ObjectProduct::kind] = $kind->value;
        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_CREATE, $input, 'req-create');

        [$success] = $this->actionFrames(self::ADMIN_KEY, 1);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame('req-create', $success->requestId);
        self::assertSame(EcommerceShopSignalConstants::PRODUCT_CREATE, $success->action);
        self::assertSame('Product “Blue vase” added.', $success->message);
        self::assertNull($success->reply);

        $stored = Database::sql('SELECT * FROM product WHERE name = ?', ['Blue vase'])->rows();
        self::assertCount(1, $stored);
        $product = $stored[0];
        $id = (int)$product['id'];
        self::assertSame($this->categoryId, (int)$product['category_id']);
        self::assertSame($kind->value, $product['kind']);
        self::assertSame(210_050, (int)$product['price']);
        self::assertSame($input[ObjectProduct::description], $product['description']);
        self::assertSame($input[ObjectProduct::pictureFileId], (int)$product['picture_file_id']);
        self::assertGreaterThan(0, (int)$product['changed_at']);
        self::assertSame('blue-vase', $product['slug']);
        self::assertSame(
            'blue-vase',
            Database::sql('SELECT slug FROM product_slug WHERE product_id = ?', [$id])->field('slug'),
        );
        self::assertSame([], Database::sql('SELECT id FROM product_warehouse_stock WHERE product_id = ?', [$id])->rows());

        $page = $this->subscribe(self::ADMIN_KEY)[0][PageResponseSignalData::payload];
        $slot = self::slotFor(
            $page[PagePayload::windows][EcommerceShopTableContext::products][TableWindowSignalData::rows],
            PagePayload::rowKey,
            $id,
            'product',
        );
        self::assertSame(0, $slot[ProductTableRow::units]);
        self::assertSame($availability->value, $slot[ProductTableRow::availability]);
    }

    /** @return array<string, array{ProductKind, ProductAvailability}> The two kinds and their availability with no stock */
    public static function productKinds(): array
    {
        return [
            'unique' => [ProductKind::UNIQUE, ProductAvailability::NOT_PLACED],
            'stock' => [ProductKind::STOCK, ProductAvailability::OUT_OF_STOCK],
        ];
    }

    /**
     * Invalid create fields produce a correlated domain refusal and no product.
     *
     * @param string $field Invalid field in the create payload
     * @param string|int|null $value Invalid field value, or null to use another person's picture
     * @param string $reason Person-facing refusal
     */
    #[DataProvider('invalidCreateFields')]
    public function testARefusedCreateWritesNoProduct(string $field, string|int|null $value, string $reason): void
    {
        $input = $this->productInput('Refused product');
        $input[$field] = $value ?? self::ownPictureFile($this->visitorId);

        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_CREATE, $input, 'req-refused');
        [$error] = $this->actionFrames(self::ADMIN_KEY, 1);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame('req-refused', $error->requestId);
        self::assertSame(EcommerceShopSignalConstants::PRODUCT_CREATE, $error->action);
        self::assertSame($reason, $error->reason);
        self::assertSame([], Database::sql('SELECT id FROM product WHERE name = ?', ['Refused product'])->rows());
    }

    /** @return array<string, array{string, string|int|null, string}> Invalid create inputs and their refusal messages */
    public static function invalidCreateFields(): array
    {
        return [
            'price' => [ObjectProduct::price, '2,100', 'Write at most two digits after the point'],
            'missing category' => [ObjectProduct::categoryId, 0, 'This category no longer exists'],
            'foreign picture' => [ObjectProduct::pictureFileId, null, 'This file is gone; upload it again'],
            'kind' => [ObjectProduct::kind, 'other', 'Choose a product kind'],
        ];
    }

    /** A guest or non-admin in view mode cannot publish a file or create a product, even with a valid payload. */
    public function testViewModeRefusesBothProductActions(): void
    {
        $input = $this->productInput('Viewer product');
        foreach ([self::GUEST_KEY, self::VISITOR_KEY] as $key) {
            foreach ([
                EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH => [ProductPicturePublishActionDTO::clientUploadId => 'upload-vase'],
                EcommerceShopSignalConstants::PRODUCT_CREATE => $input,
            ] as $action => $payload) {
                $this->dispatchAction($action, $payload, 'req-viewer', $key);
                [$error] = $this->actionFrames($key, 1);
                self::assertInstanceOf(PageActionErrorSignalData::class, $error);
                self::assertSame($action, $error->action);
                self::assertSame('req-viewer', $error->requestId);
                self::assertSame(ActionViewModeException::ERROR_CODE, $error->errorCode);
            }
        }
        self::assertSame([], $this->recorder->published);
        self::assertSame([], Database::sql('SELECT id FROM product WHERE name = ?', ['Viewer product'])->rows());
    }

    /** The fixture command refuses ambiguity, removes just the requested product and addresses, and can be repeated. */
    public function testFixtureCleanupIsExactAndRepeatable(): void
    {
        $name = 'e2e product ab' . RandomHelper::hex(5);
        $input = $this->productInput($name);
        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_CREATE, $input, 'req-fixture');
        [$success] = $this->actionFrames(self::ADMIN_KEY, 1);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        $id = (int)Database::sql('SELECT id FROM product WHERE name = ?', [$name])->field('id');
        $this->asProductsLibrary(static function () use ($id): void {
            Hilos::$db->productSlugs->actions->record('former-fixture-address', $id);
        });

        $other = $this->asProductsLibrary(fn () => Hilos::$db->products->actions->create(
            $name,
            $this->categoryId,
            ProductKind::STOCK,
            41000,
            'Another product',
            self::ownPictureFile($this->adminId),
        ));
        try {
            $this->runFixtureCleanup($name);
            self::fail('Ambiguous fixture names must be refused');
        } catch (AgentException $e) {
            self::assertSame('Multiple products have that fixture name', $e->getMessage());
        }
        self::assertNotEmpty(Database::sql('SELECT id FROM product WHERE id = ?', [$id])->rows());
        $this->asProductsLibrary(fn () => $other->actions->update(
            strtoupper($name),
            $this->categoryId,
            ProductKind::STOCK,
            $other->price,
            $other->description,
            null,
        ));

        self::assertSame([EcommerceShopCommandConstants::deleted => true], $this->runFixtureCleanup($name));
        self::assertSame([], Database::sql('SELECT id FROM product WHERE id = ?', [$id])->rows());
        self::assertSame([], Database::sql('SELECT id FROM product_slug WHERE product_id = ?', [$id])->rows());
        self::assertFalse(isset(Hilos::$db->products[$id]));
        self::assertCount(0, Hilos::$db->productSlugs->whereColumnIs(ObjectProductSlug::productId, $id));
        self::assertSame([EcommerceShopCommandConstants::deleted => false], $this->runFixtureCleanup($name));

        self::assertSame(strtoupper($name), Database::sql('SELECT name FROM product WHERE id = ?', [$other->id])->field('name'));
        self::assertNotEmpty(Database::sql('SELECT id FROM product_slug WHERE product_id = ?', [$other->id])->rows());
        self::assertSame($this->productName, Database::sql('SELECT name FROM product WHERE id = ?', [$this->productId])->field('name'));
        self::assertNotEmpty(Database::sql('SELECT id FROM hilos_file WHERE id = ?', [$this->pictureFileId])->rows());
        // The rollback root holds the removal until commit. ProductPictureFilesTest proves that it is then sent.
        self::assertNotEmpty(Database::sql('SELECT id FROM hilos_file WHERE id = ?', [$input[ObjectProduct::pictureFileId]])->rows());

        $this->expectException(InvalidCommandPayloadException::class);
        $this->runFixtureCleanup($this->productName);
    }

    /**
     * @param FilesPublishedSignalData $answer Files door reply
     */
    private function answer(FilesPublishedSignalData $answer): void
    {
        $this->asProductsLibrary(fn () => $this->library->onSignalAgent(
            new AgentSignalData($answer),
            'agent',
            EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISHED,
        ));
    }

    /**
     * @param string $name Reserved fixture name
     * @return array<string, mixed> Payload of the single successful command reply
     */
    private function runFixtureCleanup(string $name): array
    {
        $command = EcommerceShopCommandConstants::PRODUCT_TEST_DELETE;
        $request = Hilos::$sr->createCommandPayloadDTO(
            $command,
            new CommandRequestDTO('fixture-cleanup', $command, [EcommerceShopCommandConstants::name => $name]),
        );
        $this->asProductsLibrary(fn () => $this->library->onSignalCommand($request, 'command', $command));

        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        self::assertCount(1, $replies);
        self::assertSame('fixture-cleanup', $replies[0]->correlationId);
        self::assertTrue($replies[0]->isOk());

        return $replies[0]->payload;
    }

    /**
     * Subscribes one connection with an open products window and reads the one page answer.
     *
     * @param string $acceptKey Subscribing connection
     * @return list<array<string, mixed>> Wire arrays of the page answers, exactly one
     */
    private function subscribe(string $acceptKey): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $windows = [EcommerceShopTableContext::products => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)];
        Hilos::$sr->subscribeToPage(
            AdminProductsPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, AdminProductsPage::PAGE, [], $windows),
        );
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey): void {
            new AdminProductsPage($this->library)->onSubscribe($acceptKey, new PageRouteParams([]));
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
     * @param list<array<string, mixed>> $items Table rows or list items
     * @param string $keyField Identity field of the row or item
     * @param int $id Expected identity
     * @param string $slot Source slot to read
     * @return array<string, mixed> Fields of the matching slot
     */
    private static function slotFor(array $items, string $keyField, int $id, string $slot): array
    {
        foreach ($items as $item) {
            if ((string)$item[$keyField] === (string)$id) {
                return $item[PagePayload::slots][$slot];
            }
        }
        self::fail("No {$slot} item for {$id}");
    }
}
