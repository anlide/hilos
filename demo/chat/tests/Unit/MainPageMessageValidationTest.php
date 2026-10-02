<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for main-page message validation: the session first, then the files the message
 * names - complete uploads of the connection for the chat's target, each once (HIL-144).
 */
final class MainPageMessageValidationTest extends TestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private const string ACCEPT_KEY = 'message-ak';

    protected function setUp(): void
    {
        parent::setUp();

        $rt = new ChatRtContext();
        $rt->mountFeatureRuntime([new UploadsFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        ExecutionContext::clear();
        Hilos::$rt = null;

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
        $this->signIn();
        $this->openUpload('ready', ChatAttachmentUploadTarget::NAME, complete: false);
        $this->openUpload('elsewhere', 'avatar', complete: true);

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
        $this->signIn();
        $this->openUpload('done', ChatAttachmentUploadTarget::NAME, complete: true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachment is listed twice');

        $this->send(new MessageActionDTO('hi', ['done', 'done']));
    }

    public function testRejectsAMessageWithNeitherTextNorFiles(): void
    {
        $this->signIn();

        $this->expectException(EmptyValueException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        $this->send(new MessageActionDTO('   '));
    }

    /**
     * A message of files alone starts moderation, and the connection keeps which files it carries.
     */
    public function testAFileOnlyMessageStartsModerationWithItsFiles(): void
    {
        $this->signIn();
        $this->openUpload('done', ChatAttachmentUploadTarget::NAME, complete: true);

        $this->send(new MessageActionDTO('', ['done']));

        self::assertSame(
            ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationPhase,
        );
        self::assertSame(['done'], Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationAttachments);
    }

    /**
     * Registers a signed-in connection the test agent writes for.
     *
     * @throws HilosException When the runtime rows cannot be written
     */
    private function signIn(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);

        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);
        ExecutionContext::setCurrentAcceptKey(self::ACCEPT_KEY);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, 1);
        Hilos::$rt->userStates->actions->ensure(1);
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
        $upload = Hilos::$rt->hilosUploads->actions->open(
            self::ACCEPT_KEY,
            $clientUploadId,
            $target,
            1,
            'a.txt',
            'text/plain',
            3,
            'tmp-' . $clientUploadId,
        );
        if ($complete) {
            $upload->actions->complete();
        }
    }

    /**
     * @param MessageActionDTO $dto Message to send from the connection
     * @throws HilosException Whatever the page refuses the message with
     */
    private function send(MessageActionDTO $dto): void
    {
        new MainPage(new ChatAgent())->onAction(self::ACCEPT_KEY, ChatSignalConstants::MESSAGE, $dto);
    }
}
