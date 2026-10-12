<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\AppearancePage;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Appearance\DTO\HilosAppearanceThemeSwitchingSetActionDTO;
use Hilos\Pages\Security\DTO\HilosImpersonationScopeSetActionDTO;
use Hilos\Theme\DTO\ThemeSettingsSignalData;
use Hilos\Theme\ThemeSettingsCatalog;
use PHPUnit\Framework\Attributes\DataProvider;

/** The appearance page hands one setting to its library, which answers and broadcasts the theme pair. */
final class AppearancePageActionTest extends IntegrationTestCase
{
    private const string SETTINGS_AGENT_ID = 'test-appearance-page-writer';
    private const string ACCEPT_KEY = 'appearance-page-ak';
    private const string REQUEST_ID = 'appearance-switching-request';

    /** Prepares the router that a worker normally supplies. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::$sr = new SignalRouter();
    }

    public function testSwitchingOffAndBackWritesOnlyItsKeyAndBroadcastsOncePerChange(): void
    {
        $this->withSettingsWriter(function (): void {
            foreach ([false, true] as $enabled) {
                $this->submit($enabled);
                self::assertSame($enabled, Hilos::$setting[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]->bool());
                self::assertSame(ThemeSettingsCatalog::SYSTEM, Hilos::$setting[ThemeSettingsCatalog::DEFAULT_THEME_KEY]->string());
                self::assertNull(Hilos::$db->settings[ThemeSettingsCatalog::DEFAULT_THEME_KEY]);
            }
        });
    }

    public function testPayloadRoundtripKeepsOnlyTheBooleanInBothPositions(): void
    {
        foreach ([false, true] as $enabled) {
            $dto = HilosAppearanceThemeSwitchingSetActionDTO::fromArray([
                SignalPayloadConstants::FIELD_DATA => [
                    HilosAppearanceThemeSwitchingSetActionDTO::enabled => $enabled,
                    'key' => ThemeSettingsCatalog::DEFAULT_THEME_KEY,
                ],
            ]);
            self::assertSame(['enabled' => $enabled], $dto->toArray());
            self::assertSame($enabled, HilosAppearanceThemeSwitchingSetActionDTO::fromArray($dto->toArray())->enabled);
            self::assertSame(HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET, $dto->getAction());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}> Missing and malformed enabled payloads
     */
    public static function malformedPayloads(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['enabled' => null]];
        yield 'integer' => [['enabled' => 0]];
        yield 'string' => [['enabled' => 'false']];
        yield 'array' => [['enabled' => []]];
        yield 'wrapped missing' => [[SignalPayloadConstants::FIELD_DATA => []]];
        yield 'invalid wrapper' => [[SignalPayloadConstants::FIELD_DATA => 'false']];
    }

    /**
     * @param array<string, mixed> $payload Missing or malformed enabled field
     */
    #[DataProvider('malformedPayloads')]
    public function testMissingOrNonBooleanEnabledIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosAppearanceThemeSwitchingSetActionDTO::fromArray($payload);
    }

    public function testMismatchedActionPayloadIsRefusedBeforeForwarding(): void
    {
        $this->expectException(InvalidActionPayloadException::class);
        new AppearancePage(new DemoHilosAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET,
            new HilosImpersonationScopeSetActionDTO('view'),
        );
    }

    public function testUnknownActionIsRefused(): void
    {
        $this->expectException(AgentUnknownActionException::class);
        new AppearancePage(new DemoHilosAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SETTING_UPDATE,
            new HilosAppearanceThemeSwitchingSetActionDTO(false),
        );
    }

    /**
     * Runs the page, library and page answer with their queued frames carried by the harness.
     *
     * @param bool $enabled Position of the theme-switching setting
     */
    private function submit(bool $enabled): void
    {
        $page = new AppearancePage(new DemoHilosAgent());
        $before = Hilos::$setting[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]->bool();
        $page->beginActionDispatch(self::REQUEST_ID);
        try {
            self::assertNull($page->onAction(
                self::ACCEPT_KEY,
                HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET,
                new HilosAppearanceThemeSwitchingSetActionDTO($enabled),
            ));
            self::assertTrue($page->actionReplyDeferred());
        } finally {
            $page->endActionDispatch();
        }
        self::assertSame($before, Hilos::$setting[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]->bool());

        $requests = $this->consumeQueuedFrames();
        self::assertCount(1, $requests, 'The page owes exactly one write and no immediate client answer');
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $requests[0]->signalType->getType());
        self::assertSame(HilosSignalConstants::HILOS_SETTING_WRITE, $requests[0]->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $requests[0]->data);
        $ask = $requests[0]->data->data;
        self::assertInstanceOf(SettingWriteSignalData::class, $ask);
        self::assertSame(ThemeSettingsCatalog::SWITCHING_ENABLED_KEY, $ask->key);
        self::assertSame($enabled, $ask->value);
        self::assertSame(self::ACCEPT_KEY, $ask->acceptKey);
        self::assertSame(self::REQUEST_ID, $ask->requestId);
        self::assertSame(HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET, $ask->action);
        self::assertSame(HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE, $ask->replySignal);

        new SettingsLibraryAgent()->onSignalAgent(
            new AgentSignalData(SettingWriteSignalData::fromArray($ask->toArray())),
            'agent',
            HilosSignalConstants::HILOS_SETTING_WRITE,
        );

        $answers = [];
        $themeFrames = [];
        foreach ($this->consumeQueuedFrames() as $signal) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                self::assertSame(HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE, $signal->signalName->getName());
                self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
                $answers[] = $signal->data->data;
            }
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_THEME_SETTINGS) {
                self::assertSame(SignalTypeConstants::WS_ALL_CONNECTED, $signal->signalType->getType());
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertInstanceOf(ThemeSettingsSignalData::class, $signal->data->data);
                $themeFrames[] = $signal->data->data->toArray();
            }
        }
        self::assertCount(1, $answers);
        self::assertNull($answers[0]->error);
        self::assertSame('Theme switching saved.', $answers[0]->successMessage);
        self::assertSame([['switchingEnabled' => $enabled, 'defaultTheme' => 'system']], $themeFrames);

        $page->onSignalAgent(
            new AgentSignalData(HandoverAnswerSignalData::fromArray($answers[0]->toArray())),
            'agent',
            HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE,
        );
        $replies = $this->consumeQueuedFrames();
        self::assertCount(1, $replies);
        self::assertSame(SignalConstants::ACTION_SUCCESS, $replies[0]->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $replies[0]->data);
        self::assertSame(self::ACCEPT_KEY, $replies[0]->data->targetAcceptKey);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $replies[0]->data->data);
        self::assertSame(self::REQUEST_ID, $replies[0]->data->data->requestId);
        self::assertSame(HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET, $replies[0]->data->data->action);
        self::assertSame('Theme switching saved.', $replies[0]->data->data->message);
    }

    /**
     * @return list<SignalDTO> Queued frames, removed from the router
     */
    private function consumeQueuedFrames(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @param callable():void $body Test body run under the settings writer
     */
    private function withSettingsWriter(callable $body): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::SETTINGS_AGENT_ID);
        try {
            Hilos::$db->settings[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]?->actions->delete();
            $body();
        } finally {
            Hilos::$db->settings[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]?->actions->delete();
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
        }
    }
}
