<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogStub;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Frame\MailLetterFrame;
use Hilos\Mail\Frame\MailLetterFrameResolver;
use Hilos\Mail\Frame\MailLetterFrameSettings;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportInterface;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Pages\Communications\CommunicationsLetterFrameReader;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Testing\IntegrationTest;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelFramePage;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;
use Hilos\Pages\Communications\DTO\HilosChannelSettingResetActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelSettingUpdateActionDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use ReflectionProperty;

/** Real settings writer and persistence, with captured mail instead of SMTP. */
#[IntegrationTest]
final class CommunicationsLetterFrameIntegrationTest extends CommunicationsLetterFrameEnvironment
{
    public function testWriterUpdatesAndResetsTheNextMessageWhileQueuedRawKeepsItsFrame(): void
    {
        $resolver = new MailLetterFrameResolver();
        self::assertSame('project', $resolver->resolve()->source);
        self::assertArrayNotHasKey(MailLetterFrameSettings::SETTING_KEY, Hilos::$db->settings);
        $agent = new CommunicationsFrameMailAgent('1');
        $this->orderHtml($agent);

        $first = '<first lang="[[:lang]]" dir="[[:dir]]">[[:content]]</first>';
        self::assertNull($this->write($first)->error);
        self::assertSame($first, $this->persistedFrame());
        self::assertSame('settings', $resolver->resolve()->source);
        self::assertSame($first, $resolver->resolve()->html);
        $this->orderHtml($agent);
        $agent->onTick();
        self::assertCount(2, $agent->messages);
        self::assertSame('<project><p>[[:lang]]</p></project>', $agent->messages[0]->html);
        self::assertSame(MailLetterFrame::render($first, '<p>[[:lang]]</p>', 'ar'), $agent->messages[1]->html);

        $second = '<second>[[:content]]</second>';
        self::assertNull($this->write($second)->error);
        self::assertSame($second, $this->persistedFrame());
        $this->orderHtml($agent);
        $agent->onTick();
        self::assertSame('<second><p>[[:lang]]</p></second>', $agent->messages[2]->html);

        $reset = new SettingResetSignalData('frame_reply', 'frame-admin', 'reset-1', 'setting_reset', null, MailLetterFrameSettings::SETTING_KEY);
        new SettingsLibraryAgent()->onSignalAgent(new AgentSignalData($reset), 'test', HilosSignalConstants::HILOS_SETTING_RESET);
        self::assertNull($this->answer()->error);
        self::assertNull($this->persistedFrame());
        self::assertSame('project', $resolver->resolve()->source);
        $this->orderHtml($agent);
        $agent->onTick();
        self::assertSame('<project><p>[[:lang]]</p></project>', $agent->messages[3]->html);
        self::assertSame('Inline subject', $agent->messages[3]->subject);
        self::assertSame('Plain fallback', $agent->messages[3]->text);
        $agent->onStop();
    }

    public function testInvalidInputsAreRefusedOnCreateAndUpdateThroughTheGeneralSettingsDoor(): void
    {
        foreach (['', '[[:content]][[:content]]', '[[:content]][[:secret]]'] as $invalid) {
            self::assertNotNull($this->write($invalid)->error);
            self::assertNull($this->persistedFrame());
        }
        $valid = '<valid>[[:content]]</valid>';
        self::assertNull($this->write($valid)->error);
        foreach (['private-code-246802', '[[:content]][[:content]]', '[[:content]][[:secret]]', true, false, ['[[:content]]']] as $invalid) {
            $answer = $this->write($invalid);
            self::assertNotNull($answer->error);
            self::assertStringNotContainsString('private-code-246802', $answer->error);
            self::assertSame($valid, $this->persistedFrame());
        }
    }

    public function testCliSettingsDoorUsesTheSameRuleAndCanResetTheOverride(): void
    {
        $valid = '<cli>[[:content]]</cli>';
        $this->command(CliCommands::SETTINGS_SET, $valid);
        self::assertSame($valid, $this->persistedFrame());
        $this->command(CliCommands::SETTINGS_SET, '[[:content]][[:unknown]]', MailLetterFrame::PLACEHOLDER_REFUSAL);
        self::assertSame($valid, $this->persistedFrame());
        $this->command(CliCommands::SETTINGS_RESET);
        self::assertNull($this->persistedFrame());
        self::assertSame('project', new MailLetterFrameResolver()->resolve()->source);
    }

    public function testReaderFollowsRealWriterAndResetWithoutQueuingMailOrCreatingRuntimeState(): void
    {
        $reader = new CommunicationsLetterFrameReader();
        $before = $reader->read();
        $sampleHtml = "Use this code to reset your password: 482913<br />\n<br />\n"
            . 'If you did not request a password reset, you can ignore this message.';
        self::assertSame('email', $before->channel);
        self::assertSame('project', $before->source);
        self::assertSame('<project>[[:content]]</project>', $before->html);
        self::assertSame('<project>' . $sampleHtml . '</project>', $before->previewHtml);
        self::assertNull(Hilos::$rt);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertArrayNotHasKey(MailLetterFrameSettings::SETTING_KEY, Hilos::$db->settings);

        foreach ([
            '<first lang="[[:lang]]" dir="[[:dir]]">[[:content]]</first>',
            '<second>[[:content]]</second>',
        ] as $html) {
            self::assertNull($this->write($html)->error);
            $current = $reader->read();
            self::assertSame('settings', $current->source);
            self::assertSame($html, $current->html);
            self::assertSame(MailLetterFrame::render($html, $sampleHtml, 'en'), $current->previewHtml);
            self::assertNull(Hilos::$rt);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }

        $this->command(CliCommands::SETTINGS_RESET);
        self::assertSame($before->toArray(), $reader->read()->toArray());
        self::assertNull(Hilos::$rt);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertSame('project', $before->source);
        self::assertSame('<project>[[:content]]</project>', $before->html);
    }

    public function testSubscribedPageAndCapturedMailUseTheFrameWrittenThroughTheOverviewGatekeeper(): void
    {
        $beforeBrowser = Hilos::$browser;
        $browser = new CommunicationsFrameBrowser();
        CommunicationsFrameApp::initBrowser($browser);
        try {
            $agent = $this->createStub(PageAgentInterface::class);
            $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::AGENT, 'frame-page'));
            $page = new CommunicationsFramePage($agent);
            $overview = new CommunicationsFrameOverview($agent);
            Hilos::$sr->subscribeToPage($page::PAGE, new WebSocketPageSubscribeSignalDTO('frame-admin', $page::PAGE, ['channelId' => 'email']));
            $initial = $this->frameAnswer($page);
            self::assertSame('project', $initial['letterFrame']['source']);
            self::assertSame('email', $initial['channelSummary']['channel']);
            self::assertSame([], Hilos::$sr->getTableViewports('frame-admin'));

            $html = '<installation lang="[[:lang]]" dir="[[:dir]]">[[:content]]</installation>';
            $overview->beginActionDispatch('frame-save');
            $overview->onAction('frame-admin', HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET,
                new HilosChannelSettingUpdateActionDTO('email', 'frame', $html));
            self::assertTrue($overview->actionReplyDeferred());
            $ask = Hilos::$sr->getNextQueuedSignal();
            self::assertInstanceOf(AgentSignalData::class, $ask->data);
            self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'The page cannot announce success ahead of the writer.');
            new SettingsLibraryAgent()->onSignalAgent($ask->data, 'gatekeeper', HilosSignalConstants::HILOS_SETTING_WRITE);
            $done = $this->answer();
            self::assertNull($done->error);
            self::assertSame('frame-save', $done->requestId);
            self::assertSame('Letter frame saved.', $done->successMessage);
            $overview->endActionDispatch();
            self::assertSame($html, $this->persistedFrame());
            $current = $this->frameAnswer($page);
            self::assertSame('settings', $current['letterFrame']['source']);
            self::assertSame($html, $current['letterFrame']['html']);
            self::assertStringStartsWith('<installation lang="en" dir="ltr">', $current['letterFrame']['previewHtml']);
            self::assertStringContainsString('482913', $current['letterFrame']['previewHtml']);
            $mail = new CommunicationsFrameMailAgent('1');
            $this->orderHtml($mail);
            $mail->onTick();
            self::assertSame(MailLetterFrame::render($html, '<p>[[:lang]]</p>', 'ar'), $mail->messages[0]->html);
            $mail->onStop();

            foreach (['', '[[:content]][[:content]]', '[[:content]][[:unknown]]'] as $invalid) {
                try {
                    $overview->onAction('frame-admin', HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET,
                        new HilosChannelSettingUpdateActionDTO('email', 'frame', $invalid));
                    self::fail('Invalid HTML must leave the persisted frame unchanged.');
                } catch (TableActionException) {
                    self::assertNull(Hilos::$sr->getNextQueuedSignal());
                    self::assertSame($html, $this->persistedFrame());
                }
            }
            $overview->beginActionDispatch('frame-reset');
            $overview->onAction('frame-admin', HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET,
                new HilosChannelSettingResetActionDTO('email', 'frame'));
            $ask = Hilos::$sr->getNextQueuedSignal();
            new SettingsLibraryAgent()->onSignalAgent($ask->data, 'gatekeeper', HilosSignalConstants::HILOS_SETTING_RESET);
            self::assertSame('Letter frame override reset.', $this->answer()->successMessage);
            $overview->endActionDispatch();
            self::assertNull($this->persistedFrame());
            self::assertSame($initial['letterFrame'], $this->frameAnswer($page)['letterFrame']);
            self::assertSame($page::PAGE, Hilos::$sr->pageSubscription('frame-admin')->page);
        } finally {
            Hilos::$browser = $beforeBrowser;
        }
    }

    public function testViewerReadsTheSubscribedFrameAndTheExistingActionGateRefusesWrites(): void
    {
        $previousBrowser = Hilos::$browser;
        CommunicationsFrameApp::initBrowser(new CommunicationsFrameBrowser(true));
        try {
            $agent = $this->createStub(PageAgentInterface::class);
            $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::AGENT, 'frame-page'));
            $page = new CommunicationsFramePage($agent);
            $visible = $this->frameAnswer($page)['letterFrame'];
            self::assertSame('project', $visible['source']);
            self::assertSame('<project>[[:content]]</project>', $visible['html']);
            self::assertStringContainsString('482913', $visible['previewHtml']);
            Hilos::$setting = new SettingsAccessor(CommunicationsFrameClosedCatalog::class);
            self::assertSame([
                'channel' => 'email',
                'html' => HiddenValue::mark(),
                'source' => 'project',
                'previewHtml' => HiddenValue::mark(),
            ], $this->frameAnswer($page)['letterFrame']);
            foreach ([HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET, HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET] as $action) {
                try {
                    PageAccessGate::assertAction(CommunicationsFrameOverview::class, 'frame-admin', $action);
                    self::fail('A viewer may not mutate the frame.');
                } catch (ActionViewModeException $failure) {
                    self::assertSame(403, $failure->getCode());
                    self::assertSame('view_mode', $failure->errorCode);
                }
            }
            self::assertNull($this->persistedFrame());
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        } finally {
            Hilos::$browser = $previousBrowser;
        }
    }

    /** @return array<string, mixed> One complete answer from the subscribed frame page */
    private function frameAnswer(CommunicationsFramePage $page): array
    {
        $page->onSubscribe('frame-admin', new PageRouteParams(['channelId' => 'email']));
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        return $signal->data->data->payload->data;
    }

    private function orderHtml(CommunicationsFrameMailAgent $agent): void
    {
        $agent->onSignalAgent(new AgentSignalData(new MailSendSignalData(
            to: 'reader@example.com', shardKey: 1, subject: 'Inline subject', text: 'Plain fallback', html: '<p>[[:lang]]</p>', locale: 'ar',
        )), 'test', HilosSignalConstants::HILOS_MAIL_SEND);
    }

    private function write(mixed $value): HandoverAnswerSignalData
    {
        new SettingsLibraryAgent()->onSignalAgent(new AgentSignalData(new SettingWriteSignalData(
            'frame_reply', 'frame-admin', 'write-1', 'setting_add', null, MailLetterFrameSettings::SETTING_KEY, $value,
        )), 'test', HilosSignalConstants::HILOS_SETTING_WRITE);

        return $this->answer();
    }

    private function answer(): HandoverAnswerSignalData
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                return $signal->data->data;
            }
        }
        self::fail('The settings library must answer the write');
    }

    private function command(string $command, ?string $value = null, ?string $refusal = null): void
    {
        $library = new SettingsLibraryAgent();
        $library->onSignalCommand(new CommandRequestDTO('frame-command', $command, [
            SettingsCommandConstants::FIELD_KEY => MailLetterFrameSettings::SETTING_KEY,
            SettingsCommandConstants::FIELD_VALUE => $value,
        ]), 'test', $command);
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                $library->onSignalAgent($signal->data, 'test', HilosSignalConstants::HILOS_SETTING_COMMAND_DONE);
            } elseif ($signal->data instanceof CommandReplyDTO) {
                self::assertSame($refusal === null ? CommandConstants::STATUS_OK : CommandConstants::STATUS_ERROR, $signal->data->status);
                if ($refusal !== null) {
                    self::assertContains($refusal, $signal->data->payload);
                }
                return;
            }
        }
        self::fail('The settings command must receive a reply');
    }

    /** Reads persisted data through a cold ORM context, independently of the writer's cached row. */
    private function persistedFrame(): ?string
    {
        $writer = Hilos::$db;
        try {
            Hilos::$db = new FrameworkRollbackDbContext();
            Hilos::$db->configure();

            return isset(Hilos::$db->settings[MailLetterFrameSettings::SETTING_KEY])
                ? Hilos::$db->settings[MailLetterFrameSettings::SETTING_KEY]->value : null;
        } finally {
            Hilos::$db = $writer;
        }
    }
}

/** Owns facade bindings outside the managed rollback case's lifecycle. */
abstract class CommunicationsLetterFrameEnvironment extends FrameworkRollbackIntegrationTestCase
{
    private ?SettingsAccessor $previousSetting = null;
    private ?TableContext $previousTable = null;
    private string $previousApp;

    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousSetting = Hilos::$setting;
        $this->previousTable = Hilos::$table;
        $this->previousApp = Hilos::appClass();
        parent::prepareIntegrationEnvironment();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, CommunicationsFrameApp::class);
        Hilos::$setting = new SettingsAccessor(CommunicationsFrameCatalog::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new class extends TableContext {
            public function configure(): void
            {
                $this->register(HilosSettingsTable::TABLE, new HilosSettingsTable());
            }
        };
        Hilos::$table->configure();
    }

    protected function releaseIntegrationEnvironment(): void
    {
        try {
            parent::releaseIntegrationEnvironment();
        } finally {
            Hilos::$setting = $this->previousSetting;
            Hilos::$table = $this->previousTable;
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        }
    }
}

abstract class CommunicationsFrameApp extends Hilos
{
    public const array PAGES = [
        CommunicationsFramePage::PAGE => CommunicationsFramePage::class,
        CommunicationsFrameOverview::PAGE => CommunicationsFrameOverview::class,
    ];
    protected const string NOTIFICATION_CHANNEL_REGISTRY = CommunicationsFrameRegistry::class;
}

final class CommunicationsFrameRegistry extends DeliveryChannelRegistry
{
    protected static function channels(): array
    {
        return [MailDeliveryChannel::NAME => new CommunicationsFrameChannel()];
    }
}

final class CommunicationsFrameChannel extends MailDeliveryChannel
{
    protected const ?string LETTER_FRAME = '<project>[[:content]]</project>';
}

final class CommunicationsFrameCatalog implements CatalogProviderInterface
{
    public static function getCatalog(): array
    {
        return array_replace(SettingsCatalogStub::getCatalog(), ChannelSettingsCatalog::getCatalog());
    }
}

final class CommunicationsFrameMailAgent extends MailDeliveryChannelAgent
{
    /** @var list<EmailMessage> Messages actually handed to the fake transport */
    public array $messages = [];

    protected function maxConcurrent(): int
    {
        return 4;
    }

    protected function createTransport(): MailTransportInterface
    {
        return new class ($this) implements MailTransportInterface {
            private bool $ready = false;

            public function __construct(private readonly CommunicationsFrameMailAgent $agent)
            {
            }

            public function start(EmailMessage $message, float $nowMs): void
            {
                $this->agent->messages[] = $message;
                $this->ready = true;
            }

            public function tick(float $nowMs): void
            {
            }

            public function isBusy(): bool
            {
                return false;
            }

            public function hasResult(): bool
            {
                return $this->ready;
            }

            public function consumeResult(): MailSendOutcome
            {
                $this->ready = false;
                return MailSendOutcome::written();
            }

            public function close(): void
            {
                $this->ready = false;
            }
        };
    }
}

final class CommunicationsFramePage extends AbstractHilosCommunicationsChannelFramePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'frame-agent';
}

final class CommunicationsFrameOverview extends AbstractHilosCommunicationsChannelPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'frame-agent';
}

final class CommunicationsFrameBrowser extends BrowserContext
{
    public function __construct(private readonly bool $viewer = false)
    {
        parent::__construct();
    }

    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}

final class CommunicationsFrameClosedCatalog implements CatalogProviderInterface
{
    public static function getCatalog(): array
    {
        return array_replace(CommunicationsFrameCatalog::getCatalog(), [
            MailLetterFrameSettings::SETTING_KEY => array_replace(
                MailLetterFrameSettings::catalogEntry('<project>[[:content]]</project>'),
                [SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => false],
            ),
        ]);
    }
}
