<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Hilos;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Users\DTO\AccountMergeActionDTO;
use Hilos\Users\DTO\AccountMergeSignalData;
use PHPUnit\Framework\TestCase;

/** Unit tests for the page → sessions-library → page merge handover (HIL-411). */
final class AccountMergeTwoStepTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testTheAdminCardOwnsTheActionAndTheLibraryOwnsTheWriteFrame(): void
    {
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosUserPage::ACCESS_LEVEL);
        self::assertSame(
            AccountMergeActionDTO::class,
            AbstractHilosUserPage::ACTIONS[HilosSignalConstants::HILOS_USER_MERGE] ?? null,
        );
        self::assertSame(
            AccountMergeSignalData::class,
            AbstractSessionsLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_ACCOUNT_MERGE] ?? null,
        );
    }

    public function testThePageForwardsTheTrackedAskWithoutJudgingIt(): void
    {
        $page = new AccountMergeTestPage(new AccountMergeTestPageAgent());
        $page->beginActionDispatch('request-1');

        $page->onAction(
            'accept-1',
            HilosSignalConstants::HILOS_USER_MERGE,
            new AccountMergeActionDTO(12, 57, PasswordFate::LOSER),
        );

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(HilosSignalConstants::HILOS_ACCOUNT_MERGE, $signal->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        self::assertInstanceOf(AccountMergeSignalData::class, $signal->data->data);
        self::assertSame(12, $signal->data->data->survivorUserId);
        self::assertSame(57, $signal->data->data->loserUserId);
        self::assertSame(PasswordFate::LOSER->value, $signal->data->data->passwordFate);
        self::assertSame(HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE, $signal->data->data->replySignal);
        self::assertSame('request-1', $signal->data->data->requestId);
        self::assertTrue($page->actionReplyDeferred());
    }

    public function testThePageAnswersTheTrackedSubmitOnTheReturnFrame(): void
    {
        $page = new AccountMergeTestPage(new AccountMergeTestPageAgent());

        $page->onSignalAgent(
            new AgentSignalData(new HandoverAnswerSignalData(
                acceptKey: 'accept-1',
                requestId: 'request-1',
                action: HilosSignalConstants::HILOS_USER_MERGE,
                successMessage: 'Merged #57 into #12. Moved: sign-in methods 2, messages 14.',
                error: null,
                errorType: null,
                errorDetail: null,
            )),
            '',
            HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE,
        );

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        self::assertSame(SignalConstants::ACTION_SUCCESS, $signal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $signal->data->data);
        self::assertSame('request-1', $signal->data->data->requestId);
        self::assertSame('Merged #57 into #12. Moved: sign-in methods 2, messages 14.', $signal->data->data->message);
    }

    public function testTheAnswerFrameIsDeclaredOnTheCardPage(): void
    {
        self::assertSame(
            HandoverAnswerSignalData::class,
            AbstractHilosUserPage::SIGNALS[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE] ?? null,
        );
    }
}

/** Concrete single-user page fixture. */
final class AccountMergeTestPage extends AbstractHilosUserPage
{
}

/** Minimal page-agent fixture supplying the signal source page helpers require. */
final class AccountMergeTestPageAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'account-merge-test-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'account-merge-test');
    }
}
