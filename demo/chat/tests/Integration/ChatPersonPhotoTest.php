<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\ChatBrowserData;
use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Pages\UserPage;
use Hilos\Constants\CliCommands;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\Database\Object\Item\UserPhoto as ObjectUserPhoto;
use Hilos\Database\View\Item\UserPhoto as ViewUserPhoto;
use Hilos\Files\HilosFiles;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for a person's photo and merge mark on the chat pages that name people (HIL-1300).
 *
 * The people list of the main page carries both as slots of their own beside the person -
 * `userPhotos` with the address of the photo's public avatar variant, `userMerges` with the folded
 * account's key - and each is there only for the people who have one. The public page of a person
 * carries the photo as its `personPhoto` data, empty for a person without one. Everything is read off
 * the one page_response a fresh subscription is answered with.
 *
 * The photo row is written the way a node writes it: by the person's own agent, which holds that
 * person's set of the photo table, over a registry row as the files library would have published
 * it. The merge goes through the operator command of the sessions library, the path
 * {@see AccountMergeTest} drives.
 *
 * Requires the test DB reset before run (composer run test:db-reset).
 */
final class ChatPersonPhotoTest extends IntegrationTestCase
{
    /**
     * Gives the case a signal router: the page answers and the merge reply are queued on it.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new ChatSignalRouter());
    }

    /**
     * A person with a photo carries it in the people list as the address of the avatar variant, and a
     * person without one has no photo slot at all.
     *
     * @throws HilosException When setup, the photo write or the subscription fails
     */
    public function testThePeopleListCarriesThePhotoOfAPersonWhoHasOne(): void
    {
        $withPhotoId = (int) Hilos::$db->users->actions->createWithName('Photographed')->id;
        $withoutPhotoId = (int) Hilos::$db->users->actions->createWithName('Faceless')->id;
        $fileId = $this->putPhoto($withPhotoId);

        $items = $this->mainUsersItems();

        $this->assertSame(
            [
                ObjectUserPhoto::userId => $withPhotoId,
                ViewUserPhoto::photo => HilosFiles::downloadPath($fileId, ProfilePhotoUploadTarget::VARIANT),
            ],
            $items[$withPhotoId][ChatDbContext::userPhotos] ?? null,
        );
        $this->assertArrayHasKey($withoutPhotoId, $items, 'A person without a photo is still on the list');
        $this->assertArrayNotHasKey(ChatDbContext::userPhotos, $items[$withoutPhotoId]);
        $this->assertArrayNotHasKey(ChatDbContext::userMerges, $items[$withPhotoId], 'Nobody was merged here');
    }

    /**
     * A merged account carries the merge slot in the people list, and the account it went into does not.
     *
     * @throws HilosException When setup, the merge or the subscription fails
     */
    public function testThePeopleListMarksAMergedAccount(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;
        $this->merge($survivorId, $loserId);

        $items = $this->mainUsersItems();

        $this->assertSame([ObjectUserMerge::userId => $loserId], $items[$loserId][ChatDbContext::userMerges] ?? null);
        $this->assertArrayHasKey($survivorId, $items);
        $this->assertArrayNotHasKey(ChatDbContext::userMerges, $items[$survivorId], 'The survivor was folded into nobody');
    }

    /**
     * The public page of a person carries the address of the avatar variant as its photo data, and
     * nothing for a person without a photo.
     *
     * @throws HilosException When setup, the photo write or the subscription fails
     */
    public function testTheUserPageDataCarriesThePhotoAndNothingWithoutOne(): void
    {
        $withPhotoId = (int) Hilos::$db->users->actions->createWithName('Photographed')->id;
        $withoutPhotoId = (int) Hilos::$db->users->actions->createWithName('Faceless')->id;
        $fileId = $this->putPhoto($withPhotoId);

        $this->assertSame(
            [
                ObjectUserPhoto::userId => $withPhotoId,
                ViewUserPhoto::photo => HilosFiles::downloadPath($fileId, ProfilePhotoUploadTarget::VARIANT),
            ],
            $this->personPhotoData($withPhotoId),
        );
        $this->assertSame([], $this->personPhotoData($withoutPhotoId));
    }

    /**
     * Gives a person a photo the way their agent does, over a fresh registry row.
     *
     * @param int $userId Person who gets the photo
     * @return int Registry file the photo row points at
     * @throws HilosException When the registry row or the photo row cannot be written
     */
    private function putPhoto(int $userId): int
    {
        $fileId = (int) $this->keepRegistryFile($userId, 'face.jpg', 'face of ' . RandomHelper::hex(8))->id;
        $this->underAgent(
            $this->personAgent($userId),
            static fn (): ?int => Hilos::$db->userPhotos->actions->put($userId, $fileId),
        );
        $this->drainSignals();

        return $fileId;
    }

    /**
     * Folds one account into another over the operator command, and asserts it went through.
     *
     * Dispatched inside the sessions library's own frame, as a worker dispatches it, so every write
     * of the merge is judged as the library's - see {@see AccountMergeTest} for why that matters.
     *
     * @param int $survivorId Account that absorbs the other
     * @param int $loserId Account folded into the survivor
     * @throws HilosException When the command handler fails
     */
    private function merge(int $survivorId, int $loserId): void
    {
        $library = $this->sessionsLibrary();
        ExecutionContext::run(
            new ExecutionFrame(agentId: $library->getId()),
            static function () use ($library, $survivorId, $loserId): void {
                $library->onSignalCommand(new CommandRequestDTO(
                    correlationId: RandomHelper::hex(8),
                    command: CliCommands::ACCOUNT_MERGE,
                    payload: [
                        AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => $survivorId,
                        AccountMergeCommandConstants::FIELD_LOSER_USER_ID => $loserId,
                    ],
                ), '', '');
            },
        );

        $replies = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        $this->assertCount(1, $replies, 'Every merge answers the operator exactly once');
        $this->assertTrue($replies[0]->isOk(), 'The merge was refused: ' . json_encode($replies[0]->payload));
    }

    /**
     * Subscribes a fresh connection to the main page and reads the people list its answer carries.
     *
     * @return array<int|string, array<string, mixed>> Slots of every item of the list, by item key
     * @throws HilosException When the subscription fails
     */
    private function mainUsersItems(): array
    {
        $payload = $this->subscriptionPayload(new MainPage(new ChatAgent()), MainPage::PAGE, []);
        $items = $payload[PagePayload::lists][ChatBrowserList::MAIN_USERS][PagePayload::items] ?? null;
        $this->assertIsArray($items, 'The main page answered without its people list');

        return array_column($items, PagePayload::slots, PagePayload::itemKey);
    }

    /**
     * Subscribes a fresh connection to the public page of a person and reads the photo data it carries.
     *
     * @param int $userId Person whose page is opened
     * @return array<string, mixed> The `personPhoto` data, empty when the person has no photo
     * @throws HilosException When the subscription fails
     */
    private function personPhotoData(int $userId): array
    {
        $params = [UserPageSubscribeParams::USER_ID => (string) $userId];
        $payload = $this->subscriptionPayload(new UserPage(new ChatAgent()), UserPage::PAGE, $params);
        $data = $payload[PagePayload::data] ?? null;
        $this->assertIsArray($data, 'The page answered without its data section');
        $photo = $data[ChatBrowserData::USER_PHOTO] ?? null;
        $this->assertIsArray($photo, 'The page answered without its photo data');

        return $photo;
    }

    /**
     * Subscribes a fresh connection to a page and hands back the payload of the answer.
     *
     * The browser layer is built anew around the subscription and dropped after it, so nothing a
     * case left in it answers for this one.
     *
     * @param AbstractPage $page Page object that answers the subscription
     * @param string $pageKey Key of that page
     * @param array<string, string> $params Route parameters of the subscription
     * @return array<string, mixed> Payload of the page_response
     * @throws HilosException When the subscription fails
     */
    private function subscriptionPayload(AbstractPage $page, string $pageKey, array $params): array
    {
        $acceptKey = 'person-photo-' . $pageKey . '-' . RandomHelper::hex(4);
        Hilos::initBrowser();
        try {
            Hilos::$sr->subscribeToPage($pageKey, new WebSocketPageSubscribeSignalDTO($acceptKey, $pageKey, $params));
            ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($page, $acceptKey, $params): void {
                $page->onSubscribe($acceptKey, new PageRouteParams($params));
            });
        } finally {
            Hilos::initBrowser();
        }

        $payload = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE || !$signal->data instanceof WebSocketSignalData) {
                continue;
            }
            $payload ??= $signal->data->data->toArray()[PageResponseSignalData::payload] ?? null;
        }
        $this->assertIsArray($payload, 'The page did not answer the subscription');

        return $payload;
    }
}
