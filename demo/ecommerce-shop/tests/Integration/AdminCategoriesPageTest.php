<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminCategoriesPage;
use Demo\EcommerceShop\Pages\DTO\CategoryPicturePublishActionDTO;
use Demo\EcommerceShop\Pages\DTO\CategoryUpdateActionDTO;
use Demo\EcommerceShop\Tables\Category\CategoriesTable;
use Demo\EcommerceShop\Tables\Category\CategoryTableRow;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\ActionMessages;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Entity\Item\File;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;

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
 * by hand. Creation and replacement require a fresh file of the administrator.
 */
#[IntegrationTest]
final class AdminCategoriesPageTest extends AdminCategoriesPageEnvironment
{
    /** A viewer receives the categories window in the first answer with every field of the category shown. */
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
                CategoryTableRow::description => self::CATEGORY_DESCRIPTION,
                CategoryTableRow::position => $this->categoryPosition,
                CategoryTableRow::picture => HilosFiles::downloadPath($this->pictureFileId, CatalogPictureUploadTarget::VARIANT_XS),
                CategoryTableRow::pictureFileId => $this->pictureFileId,
                CategoryTableRow::picturePreview => HilosFiles::downloadPath($this->pictureFileId, CatalogPictureUploadTarget::VARIANT_SM),
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

    /** An explicit null keeps the existing picture, and the success names the saved category. */
    public function testAnAdminCanEditTheTextAndKeepThePicture(): void
    {
        $name = $this->categoryName . ' revised';
        $this->dispatchAction(self::ADMIN_KEY, EcommerceShopSignalConstants::CATEGORY_UPDATE, $this->updatePayload([
            ObjectCategory::name => "  {$name} ",
            ObjectCategory::description => "\tFresh cut roses \n",
        ]), 'req-category-update');

        $success = $this->acknowledgement(SignalConstants::ACTION_SUCCESS, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame('req-category-update', $success->requestId);
        self::assertSame(EcommerceShopSignalConstants::CATEGORY_UPDATE, $success->action);
        self::assertSame(sprintf('Category “%s” saved.', $name), $success->message);
        self::assertSame([$name, 'Fresh cut roses', $this->pictureFileId], $this->storedCategory());
    }

    /** The focused row receives both picture sizes and the current file ID after a replacement. */
    public function testAnAdminCanReplaceThePictureAndFollowTheUpdatedRow(): void
    {
        $this->subscribe(self::ADMIN_KEY);
        Hilos::$sr->setTableFocus(self::ADMIN_KEY, EcommerceShopTableContext::categories, (string)$this->categoryId);
        $fileId = $this->ownPictureFile($this->adminId, false);
        $this->dispatchAction(self::ADMIN_KEY, EcommerceShopSignalConstants::CATEGORY_UPDATE, $this->updatePayload([
            ObjectCategory::name => $this->categoryName,
            ObjectCategory::description => self::CATEGORY_DESCRIPTION,
            ObjectCategory::pictureFileId => $fileId,
        ]), 'req-category-update');
        $success = $this->acknowledgement(SignalConstants::ACTION_SUCCESS, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame('req-category-update', $success->requestId);
        self::assertSame([$this->categoryName, self::CATEGORY_DESCRIPTION, $fileId], $this->storedCategory());

        // The rollback scope holds the real announcement. Deliver the picture change to the browser as a worker would receive it.
        Hilos::$browser->record(SourceChange::dbUpdated(
            EcommerceShopDbContext::categories,
            (string)$this->categoryId,
            [EntityCategory::picture_file_id => $fileId],
            origin: self::ADMIN_KEY,
            originRequestId: 'req-category-update',
            previous: [EntityCategory::picture_file_id => $this->pictureFileId],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $deltas = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame(self::ADMIN_KEY, $signal->data->targetAcceptKey);
                self::assertInstanceOf(TableViewportDeltaDTO::class, $signal->data->data);
                $deltas[] = $signal->data->data;
            }
        }
        self::assertCount(1, $deltas);
        [$delta] = $deltas;
        self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        self::assertTrue($delta->own);
        $slot = $delta->row[PagePayload::slots][CategoriesTable::ROW_SLOT];
        self::assertSame($fileId, $slot[CategoryTableRow::pictureFileId]);
        self::assertSame(HilosFiles::downloadPath($fileId, CatalogPictureUploadTarget::VARIANT_XS), $slot[CategoryTableRow::picture]);
        self::assertSame(HilosFiles::downloadPath($fileId, CatalogPictureUploadTarget::VARIANT_SM), $slot[CategoryTableRow::picturePreview]);
    }

    /** Each expected field can stop an edit before a replacement is checked or linked. */
    public function testAnAdminIsRefusedAnEditOverAnyStaleField(): void
    {
        $replacement = $this->ownPictureFile($this->adminId, false);
        $baseline = $this->storedCategory();
        $staleFields = [
            CategoryUpdateActionDTO::expectedName => ' ' . $this->categoryName,
            CategoryUpdateActionDTO::expectedDescription => self::CATEGORY_DESCRIPTION . ' ',
            CategoryUpdateActionDTO::expectedPictureFileId => $replacement,
        ];
        foreach ($staleFields as $field => $stale) {
            $refusal = $this->refusedUpdate($this->updatePayload([
                $field => $stale,
                ObjectCategory::pictureFileId => $replacement,
            ]));
            self::assertSame('The category changed elsewhere. Review the current values and save again.', $refusal->reason, $field);
            self::assertSame($baseline, $this->storedCategory(), $field);
        }
        $refusal = $this->refusedUpdate($this->updatePayload([
            CategoryUpdateActionDTO::expectedPictureFileId => $replacement,
            ObjectCategory::pictureFileId => $this->pictureFileId,
        ]));
        self::assertSame('The category changed elsewhere. Review the current values and save again.', $refusal->reason);
        self::assertSame(0, (int)Database::sql('SELECT bound FROM hilos_file WHERE id = ?', [$replacement])->field(File::bound));
        self::assertSame($baseline, $this->storedCategory());
    }

    /** A missing nullable key is a malformed edit rather than a request to keep the picture. */
    public function testAnEditMustSupplyThePictureKey(): void
    {
        $baseline = $this->storedCategory();
        $payload = $this->updatePayload();
        unset($payload[ObjectCategory::pictureFileId]);

        self::assertSame('Payload carries no value under key pictureFileId', $this->refusedUpdate($payload)->reason);
        self::assertSame($baseline, $this->storedCategory());
    }

    /** Saving after the category was deleted returns the category's disappearance message. */
    public function testAnAdminIsRefusedAnEditOfAMissingCategory(): void
    {
        $payload = $this->updatePayload();
        Hilos::$db->categories[$this->categoryId]->actions->delete();

        self::assertSame('This category no longer exists', $this->refusedUpdate($payload)->reason);
    }

    /** A duplicate name or an unavailable replacement leaves all the current category fields intact. */
    public function testATakenNameAndUnavailablePicturesAreRefusedOnEdit(): void
    {
        $baseline = $this->storedCategory();
        $takenName = $this->categoryName . ' other';
        Hilos::$db->categories->actions->create($takenName, self::CATEGORY_DESCRIPTION, self::pictureFile());
        self::assertSame(
            'A category with this name already exists',
            $this->refusedUpdate($this->updatePayload([ObjectCategory::name => $takenName]))->reason,
        );
        self::assertSame($baseline, $this->storedCategory());

        $pictures = [
            'foreign' => $this->ownPictureFile($this->visitorId, false),
            'bound' => $this->ownPictureFile($this->adminId, true),
            'unknown' => 999_999_999,
        ];
        foreach ($pictures as $case => $fileId) {
            $refusal = $this->refusedUpdate($this->updatePayload([ObjectCategory::pictureFileId => $fileId]));
            self::assertSame('This file is gone; upload it again', $refusal->reason, $case);
            self::assertSame($baseline, $this->storedCategory(), $case);
        }
    }

    /** The tracked write is refused to viewers and to a non-admin when view mode is off. */
    public function testViewersAndANonAdminCannotEditTheCategory(): void
    {
        $baseline = $this->storedCategory();
        foreach ([self::GUEST_KEY, self::VISITOR_KEY] as $acceptKey) {
            $refusal = $this->refusedUpdate($this->updatePayload(), $acceptKey);
            self::assertSame(ActionViewModeException::ERROR_CODE, $refusal->errorCode);
            self::assertSame(ActionMessages::VIEW_MODE, $refusal->reason);
            self::assertSame($baseline, $this->storedCategory());
        }

        self::unattended(static function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        });
        $refusal = $this->refusedUpdate($this->updatePayload(), self::VISITOR_KEY);
        self::assertSame(ActionForbiddenException::ERROR_CODE, $refusal->errorCode);
        self::assertSame($baseline, $this->storedCategory());
    }

    /** The test-only command removes the category of the given name, and answers a repeat that nothing was removed. */
    public function testTheTestCommandDeletesACategoryByItsName(): void
    {
        self::assertSame([EcommerceShopCommandConstants::deleted => true], $this->command($this->categoryName)->payload);
        self::assertSame(0, self::storedCountNamed($this->categoryName));
        self::assertSame([EcommerceShopCommandConstants::deleted => false], $this->command($this->categoryName)->payload);
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
