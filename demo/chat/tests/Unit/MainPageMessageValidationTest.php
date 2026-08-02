<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\Main\AttachmentDraftDeleteActionDTO;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for main-page message validation before runtime-backed draft checks.
 */
final class MainPageMessageValidationTest extends TestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$rt = new ChatRtContext();
        Hilos::$rt->configure();
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

    public function testRejectsDeletingMissingAttachmentDraft(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, true, self::TEST_AGENT_ID);

        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);
        ExecutionContext::setCurrentAcceptKey('draft-ak');
        Hilos::$rt->connections->actions->register('draft-ak', 1);
        Hilos::$rt->userStates->actions->ensure(1);

        $this->expectException(ItemNotFoundForDeleteException::class);

        new MainPage(new ChatAgent())->onAction(
            'draft-ak',
            ChatSignalConstants::ATTACHMENT_DRAFT_DELETE,
            new AttachmentDraftDeleteActionDTO('missing-draft'),
        );
    }
}
