<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Frame\MailLetterFrame;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;
use Hilos\Pages\Communications\DTO\HilosChannelSettingResetActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelSettingUpdateActionDTO;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Frame writes use the existing Overview gatekeeper and settings handover. */
final class CommunicationsChannelFrameActionsTest extends TestCase
{
    private ?SignalRouter $previousRouter;
    private string $previousApp;
    private FrameActionsOverview $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousApp = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, FrameActionsApp::class);
        Hilos::$sr = new SignalRouter();
        $agent = $this->createStub(PageAgentInterface::class);
        $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::AGENT, 'frame-actions'));
        $this->page = new FrameActionsOverview($agent);
        $this->page->beginActionDispatch('frame-request');
    }

    protected function tearDown(): void
    {
        $this->page->endActionDispatch();
        Hilos::$sr = $this->previousRouter;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        parent::tearDown();
    }

    /** @param bool $reset Whether the existing reset action is invoked */
    #[DataProvider('writes')]
    public function testWriteForwardsExactExistingEnvelopeAndWaitsForTheWriter(bool $reset): void
    {
        $action = $reset ? HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET : HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET;
        $dto = $reset ? new HilosChannelSettingResetActionDTO('email', 'frame')
            : new HilosChannelSettingUpdateActionDTO('email', 'frame', '<main>[[:content]]</main>');
        self::assertNull($this->page->onAction('frame-admin', $action, $dto));
        self::assertTrue($this->page->actionReplyDeferred());
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame($reset ? HilosSignalConstants::HILOS_SETTING_RESET : HilosSignalConstants::HILOS_SETTING_WRITE,
            $signal->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        $ask = $signal->data->data;
        self::assertInstanceOf($reset ? SettingResetSignalData::class : SettingWriteSignalData::class, $ask);
        self::assertSame('notifications.channel.email.frame', $ask->key);
        self::assertSame('frame-admin', $ask->acceptKey);
        self::assertSame('frame-request', $ask->requestId);
        self::assertSame($action, $ask->action);
        self::assertSame(HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE, $ask->replySignal);
        self::assertSame($reset ? 'Letter frame override reset.' : 'Letter frame saved.', $ask->successMessage);
        if (!$reset) {
            self::assertSame('<main>[[:content]]</main>', $ask->value);
        }
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'Forwarding is not a local success.');
        $this->page->endActionDispatch();
        $this->page->onSignalAgent(new AgentSignalData(HandoverAnswerSignalData::to($ask, null)), 'writer', $ask->replySignal);
        $answer = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(SignalConstants::ACTION_SUCCESS, $answer->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $answer->data);
        self::assertSame('frame-request', $answer->data->data->requestId);
        self::assertSame($action, $answer->data->data->action);
        self::assertSame($ask->successMessage, $answer->data->data->message);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testWriterRefusalReturnsTheOriginalRequestWithoutSuccess(): void
    {
        $this->page->onAction('frame-admin', HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET,
            new HilosChannelSettingUpdateActionDTO('email', 'frame', '[[:content]]'));
        $ask = Hilos::$sr->getNextQueuedSignal()->data->data;
        $this->page->endActionDispatch();
        $this->page->onSignalAgent(new AgentSignalData(HandoverAnswerSignalData::to($ask,
            ActionRefusal::said('The frame could not be saved.'))), 'writer', $ask->replySignal);
        $answer = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(SignalConstants::ACTION_ERROR, $answer->signalName->getName());
        self::assertSame('frame-request', $answer->data->data->requestId);
        self::assertSame('The frame could not be saved.', $answer->data->data->reason);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @param mixed $value Invalid frame payload
     * @param string $reason Shared renderer refusal
     */
    #[DataProvider('invalidFrames')]
    public function testInvalidFrameIsRefusedBeforeForwarding(mixed $value, string $reason): void
    {
        try {
            $this->page->onAction('frame-admin', HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET,
                new HilosChannelSettingUpdateActionDTO('email', 'frame', $value));
            self::fail('An invalid frame must not reach the writer.');
        } catch (TableActionException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
        self::assertFalse($this->page->actionReplyDeferred());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return iterable<string, array{mixed, string}> Strict string values and shared syntax rules */
    public static function invalidFrames(): iterable
    {
        foreach ([null, true, false, 17, 1.2, ['[[:content]]']] as $index => $value) {
            yield 'type-' . $index => [$value, MailLetterFrame::TYPE_REFUSAL];
        }
        foreach (['', 'no content', '[[:content]][[:content]]'] as $index => $value) {
            yield 'content-' . $index => [$value, MailLetterFrame::CONTENT_REFUSAL];
        }
        yield 'unknown placeholder' => ['[[:content]][[:unknown]]', MailLetterFrame::PLACEHOLDER_REFUSAL];
    }

    /** @param bool $reset Which existing action to probe */
    #[DataProvider('writes')]
    public function testNonEmailAndUnknownChannelsAndFieldsNeverForward(bool $reset): void
    {
        foreach ([['sms', 'frame'], ['missing', 'frame'], ['email', 'Frame'], ['email', 'not_a_field']] as [$channel, $field]) {
            try {
                $dto = $reset ? new HilosChannelSettingResetActionDTO($channel, $field)
                    : new HilosChannelSettingUpdateActionDTO($channel, $field, '[[:content]]');
                $this->page->onAction('frame-admin', $dto->getAction(), $dto);
                self::fail('An unsupported channel or field must refuse.');
            } catch (TableActionException) {
                self::assertFalse($this->page->actionReplyDeferred());
                self::assertNull(Hilos::$sr->getNextQueuedSignal());
            }
        }
        self::assertNotContains('frame', array_map(static fn ($field) => $field->key, new MailDeliveryChannel()->configFields()));
    }

    /** @return iterable<string, array{bool}> Both existing writes */
    public static function writes(): iterable
    {
        yield 'set' => [false];
        yield 'reset' => [true];
    }
}

final class FrameActionsOverview extends AbstractHilosCommunicationsChannelPage
{
}

abstract class FrameActionsApp extends Hilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = FrameActionsRegistry::class;
}

final class FrameActionsRegistry extends DeliveryChannelRegistry
{
    protected static function channels(): array
    {
        return ['email' => new MailDeliveryChannel(), 'sms' => new SmsDeliveryChannel()];
    }
}
