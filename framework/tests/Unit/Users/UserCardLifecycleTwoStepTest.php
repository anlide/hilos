<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Users\DTO\AccountAdminSetSignalData;
use Hilos\Users\DTO\AccountBlockSetSignalData;
use Hilos\Users\DTO\AccountDeletionSetSignalData;
use Hilos\Users\DTO\HilosUserAdminSetActionDTO;
use Hilos\Users\DTO\HilosUserBlockSetActionDTO;
use Hilos\Users\DTO\HilosUserDeletionSetActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The card keeps the ADMIN gate and waits for the owner to answer each lifecycle action. */
final class UserCardLifecycleTwoStepTest extends TestCase
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

    public function testSubscriptionCarriesTheGracePeriodBesideThePageIdentity(): void
    {
        $settings = Hilos::$setting;
        Hilos::$setting = null;
        Hilos::initBrowser();
        try {
            $page = new UserCardLifecycleTestPage(new UserCardLifecycleTestPageAgent());
            $page->onSubscribe('accept-1', new PageRouteParams(['userId' => '12']));

            $signal = Hilos::$sr->getNextQueuedSignal();
            self::assertNotNull($signal);
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $payload = $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data];
            self::assertSame(30, $payload['accountDeletionGraceDays']);
            self::assertSame(AccountDeletionSettings::graceDays(), $payload['accountDeletionGraceDays']);
            self::assertArrayHasKey('pageLabel', $payload);
            self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'The first render needs only the page response');
        } finally {
            Hilos::$setting = $settings;
        }
    }

    public static function actions(): iterable
    {
        foreach ([true, false] as $state) {
            yield 'admin ' . (int) $state => [
                HilosUserAdminSetActionDTO::class, 'admin', $state,
                HilosSignalConstants::HILOS_USER_ADMIN_SET,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE,
                AccountAdminSetSignalData::class,
            ];
            yield 'block ' . (int) $state => [
                HilosUserBlockSetActionDTO::class, 'block', $state,
                HilosSignalConstants::HILOS_USER_BLOCK_SET,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE,
                AccountBlockSetSignalData::class,
            ];
            yield 'deletion ' . (int) $state => [
                HilosUserDeletionSetActionDTO::class, 'scheduled', $state,
                HilosSignalConstants::HILOS_USER_DELETION_SET,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE,
                AccountDeletionSetSignalData::class,
            ];
        }
    }

    #[DataProvider('actions')]
    public function testTrackedAskKeepsItsTargetStateAndCorrelationAcrossIpc(
        string $dtoClass,
        string $field,
        bool $state,
        string $action,
        string $ask,
        string $answer,
        string $askClass,
    ): void {
        $page = new UserCardLifecycleTestPage(new UserCardLifecycleTestPageAgent());
        $page->beginActionDispatch('request-1');
        $payload = ['userId' => 12, $field => $state];
        $dto = $dtoClass::fromArray([SignalPayloadConstants::FIELD_DATA => $payload]);

        self::assertSame(PageAccessLevel::ADMIN, $page::ACCESS_LEVEL);
        self::assertSame($dtoClass, $page::ACTIONS[$action]);
        self::assertSame($action, $dto->getAction());
        self::assertSame($payload, $dto->toArray());
        self::assertSame($payload, $dtoClass::fromArray($payload)->toArray());
        self::assertNull($page->onAction('accept-1', $action, $dto));
        self::assertTrue($page->actionReplyDeferred());

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame($ask, $signal->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        $restored = AgentSignalData::fromJson($signal->data->toJson())->data;
        self::assertInstanceOf($askClass, $restored);
        self::assertInstanceOf(HandoverAskInterface::class, $restored);
        self::assertSame($signal->data->data->toArray(), $restored->toArray());
        self::assertSame(12, $restored->userId);
        self::assertSame($state, $restored->$field);
        self::assertSame($answer, $restored->replySignal);
        self::assertSame('accept-1', $restored->acceptKey);
        self::assertSame('request-1', $restored->requestId);
        self::assertSame($action, $restored->action);
        self::assertNull($restored->successMessage);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public static function answers(): iterable
    {
        yield [HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE, HilosSignalConstants::HILOS_USER_MERGE];
        yield [HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE, HilosSignalConstants::HILOS_USER_ADMIN_SET];
        yield [HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE, HilosSignalConstants::HILOS_USER_BLOCK_SET];
        yield [HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE, HilosSignalConstants::HILOS_USER_DELETION_SET];
    }

    #[DataProvider('answers')]
    public function testEachLibraryAnswerCompletesTheWaitingSubmit(string $name, string $action): void
    {
        $page = new UserCardLifecycleTestPage(new UserCardLifecycleTestPageAgent());
        self::assertSame(HandoverAnswerSignalData::class, $page::SIGNALS[SignalTypeConstants::AGENT_SIGNAL][$name]);
        $page->onSignalAgent(new AgentSignalData(new HandoverAnswerSignalData(
            acceptKey: 'accept-1',
            requestId: 'request-1',
            action: $action,
            successMessage: 'Library outcome',
            error: null,
            errorType: null,
            errorDetail: null,
        )), 'library', $name);

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalConstants::ACTION_SUCCESS, $signal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertSame('accept-1', $signal->data->targetAcceptKey);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $signal->data->data);
        self::assertSame('request-1', $signal->data->data->requestId);
        self::assertSame($action, $signal->data->data->action);
        self::assertSame('Library outcome', $signal->data->data->message);
    }

    public function testAnUnknownAnswerIsRefused(): void
    {
        $page = new UserCardLifecycleTestPage(new UserCardLifecycleTestPageAgent());
        $this->expectException(AgentUnknownSignalException::class);
        $page->onSignalAgent(new AgentSignalData(new SignalData([])), 'library', 'unknown-answer');
    }

    public function testAnActionCannotBorrowAnotherActionsPayload(): void
    {
        $page = new UserCardLifecycleTestPage(new UserCardLifecycleTestPageAgent());
        $this->expectException(InvalidActionPayloadException::class);
        $page->onAction('accept-1', HilosSignalConstants::HILOS_USER_ADMIN_SET, new HilosUserBlockSetActionDTO(12, true));
    }

    public static function malformedPayloads(): iterable
    {
        foreach ([
            [HilosUserAdminSetActionDTO::class, AccountAdminSetSignalData::class, 'admin'],
            [HilosUserBlockSetActionDTO::class, AccountBlockSetSignalData::class, 'block'],
            [HilosUserDeletionSetActionDTO::class, AccountDeletionSetSignalData::class, 'scheduled'],
        ] as [$actionClass, $askClass, $field]) {
            foreach ([$actionClass, $askClass] as $class) {
                foreach ([['userId' => 12], ['userId' => 12, $field => 'false'], [$field => false],
                    ['userId' => 0, $field => true], ['userId' => -1, $field => false]] as $payload) {
                    yield [$class, $payload + [
                        'replySignal' => 'reply', 'acceptKey' => 'accept-1', 'action' => 'action',
                    ]];
                }
            }
        }
    }

    #[DataProvider('malformedPayloads')]
    public function testMalformedPayloadCannotBecomeAnAccountWrite(string $class, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $class::fromArray($payload);
    }
}

final class UserCardLifecycleTestPage extends AbstractHilosUserPage
{
}

final class UserCardLifecycleTestPageAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'user-card-lifecycle-test-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'user-card-lifecycle-test');
    }
}
