<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Hilos;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration tests for main-page message validation against the saved session and uploaded files.
 */
final class MainPageMessageValidationTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'message-ak';

    use ChatModerationFixture;

    private int $userId;

    /** Opens a saved session and prepares the chat moderation runtime for page actions. */
    protected function setUp(): void
    {
        parent::setUp();

        Hilos::initBrowser();
        $this->claimChatChecks();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), 'test-agent');
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        $this->forgetChatChecks();
        Hilos::$db->events->actions->deleteAll();
        $this->userId = (int)Hilos::$db->users->actions->createWithName('Message author')->id;
        $this->registerModerationConnection(self::ACCEPT_KEY, $this->userId);
        Hilos::$rt->userStates->actions->ensure($this->userId);
    }

    /** Removes per-case messages, uploads, moderation checks, sessions and runtime rows. */
    protected function tearDown(): void
    {
        ExecutionContext::clear();
        $this->forgetChatChecks();
        Hilos::$rt->hilosUploads->actions->clearAllWithFiles();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();
        $this->forgetModerationSessions();
        parent::tearDown();
    }

    public function testRejectsEmptyMessageWhenSessionIsMissing(): void
    {
        $this->expectException(ItemNotFoundForUpdateException::class);

        new MainPage(new ChatAgent())->onAction(
            'missing-ak',
            ChatSignalConstants::MESSAGE,
            new MessageActionDTO(''),
        );
    }

    public function testRejectsWhitespaceOnlyMessageWhenSessionIsMissing(): void
    {
        $this->expectException(ItemNotFoundForUpdateException::class);

        new MainPage(new ChatAgent())->onAction(
            'missing-ak',
            ChatSignalConstants::MESSAGE,
            new MessageActionDTO('   '),
        );
    }

    /**
     * A file the message names must be a complete upload of this connection for the chat's target.
     *
     * @param string $clientUploadId Upload the message names
     */
    #[DataProvider('attachmentsNotReady')]
    public function testRejectsAnAttachmentThatIsNotReady(string $clientUploadId): void
    {
        $this->assertSignedIn();
        $this->openUpload('ready', ChatAttachmentUploadTarget::NAME, complete: false);
        $this->openUpload('elsewhere', ProfilePhotoUploadTarget::NAME, complete: true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachment is not ready');

        $this->send(new MessageActionDTO('hi', [$clientUploadId]));
    }

    /**
     * @return array<string, array{string}> Uploads a message may not name
     */
    public static function attachmentsNotReady(): array
    {
        return [
            'still uploading' => ['ready'],
            'another target' => ['elsewhere'],
            'no such upload' => ['missing'],
        ];
    }

    public function testRejectsAnAttachmentListedTwice(): void
    {
        $this->assertSignedIn();
        $this->openUpload('done', ChatAttachmentUploadTarget::NAME, complete: true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachment is listed twice');

        $this->send(new MessageActionDTO('hi', ['done', 'done']));
    }

    public function testRejectsAMessageWithNeitherTextNorFiles(): void
    {
        $this->assertSignedIn();

        $this->expectException(EmptyValueException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        $this->send(new MessageActionDTO('   '));
    }

    /**
     * A message of files alone starts moderation, and the connection keeps which files it carries.
     */
    public function testAFileOnlyMessageStartsModerationWithItsFiles(): void
    {
        $this->assertSignedIn();
        $this->openUpload('done', ChatAttachmentUploadTarget::NAME, complete: true);

        $this->send(new MessageActionDTO('', ['done']));

        self::assertSame(
            ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationPhase,
        );
        self::assertSame(['done'], Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationAttachments);
        $check = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        self::assertNotNull($check);
        self::assertSame('', $check->text);
        self::assertSame(['done'], $check->clientUploadIds);
    }

    /** Confirms the fixture uses the person's persisted session. */
    private function assertSignedIn(): void
    {
        self::assertSame($this->userId, Hilos::$rt->connections[self::ACCEPT_KEY]?->userId);
        $sessionId = Hilos::$rt->connections[self::ACCEPT_KEY]?->sessionId;
        self::assertNotNull($sessionId);
        self::assertTrue(Hilos::$db->sessions->matchesStoredIdentity(
            $sessionId,
            $this->userId,
        ));
    }

    /**
     * Opens an upload of the connection, as the uploads agent would.
     *
     * @param string $clientUploadId Id the client gave it
     * @param string $target Target it was declared for
     * @param bool $complete Whether every byte arrived
     * @throws HilosException When the runtime row cannot be written
     */
    private function openUpload(string $clientUploadId, string $target, bool $complete): void
    {
        $tmpIndex = Hilos::$fs?->getTmp()->create();
        self::assertNotNull($tmpIndex);
        Hilos::$fs?->getTmp()[$tmpIndex]->append('abc');
        $upload = Hilos::$rt->hilosUploads->actions->open(
            self::ACCEPT_KEY,
            $clientUploadId,
            $target,
            $this->userId,
            'a.txt',
            'text/plain',
            3,
            $tmpIndex,
        );
        if ($complete) {
            $upload->actions->inspect(hash('sha256', 'abc'), 'text/plain');
            $upload->actions->complete();
        }
    }

    /**
     * @param MessageActionDTO $dto Message to send from the connection
     * @throws HilosException Whatever the page refuses the message with
     */
    private function send(MessageActionDTO $dto): void
    {
        ExecutionContext::setCurrentAcceptKey(self::ACCEPT_KEY);
        try {
            new MainPage(new ChatAgent())->onAction(self::ACCEPT_KEY, ChatSignalConstants::MESSAGE, $dto);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
    }
}
