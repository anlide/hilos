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
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Appearance\DTO\HilosAppearanceDefaultThemeSetActionDTO;
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
    private const string REQUEST_ID = 'appearance-setting-request';

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
                $this->submit(
                    new HilosAppearanceThemeSwitchingSetActionDTO($enabled),
                    ThemeSettingsCatalog::SWITCHING_ENABLED_KEY,
                    $enabled,
                    'Theme switching saved.',
                );
                self::assertSame($enabled, Hilos::$setting[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]->bool());
                self::assertSame(ThemeSettingsCatalog::SYSTEM, Hilos::$setting[ThemeSettingsCatalog::DEFAULT_THEME_KEY]->string());
                self::assertNull(Hilos::$db->settings[ThemeSettingsCatalog::DEFAULT_THEME_KEY]);
            }
        });
    }

    public function testDefaultThemeSystemToDarkToLightToSystemWritesOnlyItsKeyAndBroadcastsOncePerChange(): void
    {
        $this->withSettingsWriter(function (): void {
            self::assertSame(ThemeSettingsCatalog::SYSTEM, Hilos::$setting[ThemeSettingsCatalog::DEFAULT_THEME_KEY]->string());
            foreach ([ThemeSettingsCatalog::DARK, ThemeSettingsCatalog::LIGHT, ThemeSettingsCatalog::SYSTEM] as $defaultTheme) {
                $this->submit(
                    HilosAppearanceDefaultThemeSetActionDTO::fromArray([
                        HilosAppearanceDefaultThemeSetActionDTO::defaultTheme => $defaultTheme,
                        'key' => ThemeSettingsCatalog::SWITCHING_ENABLED_KEY,
                    ]),
                    ThemeSettingsCatalog::DEFAULT_THEME_KEY,
                    $defaultTheme,
                    'Default theme saved.',
                );
                self::assertSame($defaultTheme, Hilos::$setting[ThemeSettingsCatalog::DEFAULT_THEME_KEY]->string());
                self::assertTrue(Hilos::$setting[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]->bool());
                self::assertNull(Hilos::$db->settings[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]);
            }
        });
    }

    public function testUnchangedDefaultThemeAnswersWithoutBroadcasting(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->submit(
                new HilosAppearanceDefaultThemeSetActionDTO(ThemeSettingsCatalog::SYSTEM),
                ThemeSettingsCatalog::DEFAULT_THEME_KEY,
                ThemeSettingsCatalog::SYSTEM,
                'Default theme saved.',
            );
        });
    }

    public function testInvalidDefaultThemeIsRefusedByTheCatalogWithoutChangingOrBroadcasting(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->submit(
                new HilosAppearanceDefaultThemeSetActionDTO(ThemeSettingsCatalog::DARK),
                ThemeSettingsCatalog::DEFAULT_THEME_KEY,
                ThemeSettingsCatalog::DARK,
                'Default theme saved.',
            );
            foreach (['', 'sepia', 'Dark', ' dark ', 'SYSTEM'] as $defaultTheme) {
                $this->submit(
                    HilosAppearanceDefaultThemeSetActionDTO::fromArray([
                        HilosAppearanceDefaultThemeSetActionDTO::defaultTheme => $defaultTheme,
                    ]),
                    ThemeSettingsCatalog::DEFAULT_THEME_KEY,
                    $defaultTheme,
                    'Default theme saved.',
                    ThemeSettingsCatalog::THEME_VALUE_REFUSAL,
                );
                self::assertSame(ThemeSettingsCatalog::DARK, Hilos::$setting[ThemeSettingsCatalog::DEFAULT_THEME_KEY]->string());
                self::assertNull(Hilos::$db->settings[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]);
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

    public function testDefaultThemePayloadRoundtripKeepsOnlyTheStringInEveryPosition(): void
    {
        foreach (ThemeSettingsCatalog::THEME_VALUES as $defaultTheme) {
            $dto = HilosAppearanceDefaultThemeSetActionDTO::fromArray([
                SignalPayloadConstants::FIELD_DATA => [
                    HilosAppearanceDefaultThemeSetActionDTO::defaultTheme => $defaultTheme,
                    'key' => ThemeSettingsCatalog::SWITCHING_ENABLED_KEY,
                ],
            ]);
            self::assertSame(['defaultTheme' => $defaultTheme], $dto->toArray());
            self::assertSame($defaultTheme, HilosAppearanceDefaultThemeSetActionDTO::fromArray($dto->toArray())->defaultTheme);
            self::assertSame(HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET, $dto->getAction());
            self::assertSame([], HilosAppearanceDefaultThemeSetActionDTO::SECRET_FIELDS);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}> Missing and malformed defaultTheme payloads
     */
    public static function malformedDefaultThemePayloads(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['defaultTheme' => null]];
        yield 'boolean' => [['defaultTheme' => false]];
        yield 'integer' => [['defaultTheme' => 0]];
        yield 'array' => [['defaultTheme' => []]];
        yield 'wrapped missing' => [[SignalPayloadConstants::FIELD_DATA => []]];
        yield 'wrapped non-string' => [[SignalPayloadConstants::FIELD_DATA => ['defaultTheme' => true]]];
        yield 'invalid wrapper' => [[SignalPayloadConstants::FIELD_DATA => 'dark']];
    }

    /**
     * @param array<string, mixed> $payload Missing or malformed defaultTheme field
     */
    #[DataProvider('malformedDefaultThemePayloads')]
    public function testMissingOrNonStringDefaultThemeIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosAppearanceDefaultThemeSetActionDTO::fromArray($payload);
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

    public function testMismatchedDefaultThemePayloadIsRefusedBeforeForwarding(): void
    {
        $this->expectException(InvalidActionPayloadException::class);
        try {
            new AppearancePage(new DemoHilosAgent())->onAction(
                self::ACCEPT_KEY,
                HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET,
                new HilosAppearanceThemeSwitchingSetActionDTO(false),
            );
        } finally {
            self::assertSame([], $this->consumeQueuedFrames());
        }
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
     * @param ActionPayloadDTO $dto Parsed appearance action payload
     * @param string $key The one setting key the page must write
     * @param bool|string $value The exact value the library must receive
     * @param string $successMessage Server sentence returned after a successful write
     * @param ?string $expectedError Catalog refusal, or null for success
     */
    private function submit(
        ActionPayloadDTO $dto,
        string $key,
        bool|string $value,
        string $successMessage,
        ?string $expectedError = null,
    ): void {
        $page = new AppearancePage(new DemoHilosAgent());
        $before = ThemeSettingsSignalData::current()->toArray();
        $page->beginActionDispatch(self::REQUEST_ID);
        try {
            self::assertNull($page->onAction(
                self::ACCEPT_KEY,
                $dto->getAction(),
                $dto,
            ));
            self::assertTrue($page->actionReplyDeferred());
        } finally {
            $page->endActionDispatch();
        }
        self::assertSame($before, ThemeSettingsSignalData::current()->toArray());

        $requests = $this->consumeQueuedFrames();
        self::assertCount(1, $requests, 'The page owes exactly one write and no immediate client answer');
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $requests[0]->signalType->getType());
        self::assertSame(HilosSignalConstants::HILOS_SETTING_WRITE, $requests[0]->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $requests[0]->data);
        $ask = $requests[0]->data->data;
        self::assertInstanceOf(SettingWriteSignalData::class, $ask);
        self::assertSame($key, $ask->key);
        self::assertSame($value, $ask->value);
        self::assertSame(self::ACCEPT_KEY, $ask->acceptKey);
        self::assertSame(self::REQUEST_ID, $ask->requestId);
        self::assertSame($dto->getAction(), $ask->action);
        self::assertSame($successMessage, $ask->successMessage);
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
        self::assertSame($expectedError, $answers[0]->error);
        self::assertSame($successMessage, $answers[0]->successMessage);
        $after = $before;
        if ($expectedError === null) {
            $after[$key === ThemeSettingsCatalog::SWITCHING_ENABLED_KEY ? 'switchingEnabled' : 'defaultTheme'] = $value;
        }
        self::assertSame($after, ThemeSettingsSignalData::current()->toArray());
        self::assertSame($after === $before ? [] : [$after], $themeFrames);

        $page->onSignalAgent(
            new AgentSignalData(HandoverAnswerSignalData::fromArray($answers[0]->toArray())),
            'agent',
            HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE,
        );
        $replies = $this->consumeQueuedFrames();
        self::assertCount(1, $replies);
        self::assertInstanceOf(WebSocketSignalData::class, $replies[0]->data);
        self::assertSame(self::ACCEPT_KEY, $replies[0]->data->targetAcceptKey);
        self::assertSame(self::REQUEST_ID, $replies[0]->data->data->requestId);
        self::assertSame($dto->getAction(), $replies[0]->data->data->action);
        if ($expectedError === null) {
            self::assertSame(SignalConstants::ACTION_SUCCESS, $replies[0]->signalName->getName());
            self::assertInstanceOf(PageActionSuccessSignalData::class, $replies[0]->data->data);
            self::assertSame($successMessage, $replies[0]->data->data->message);
        } else {
            self::assertSame(SignalConstants::ACTION_ERROR, $replies[0]->signalName->getName());
            self::assertInstanceOf(PageActionErrorSignalData::class, $replies[0]->data->data);
            self::assertSame($expectedError, $replies[0]->data->data->reason);
            self::assertSame('fail', $replies[0]->data->data->getEnvelopeOutcome());
        }
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
            Hilos::$db->settings[ThemeSettingsCatalog::DEFAULT_THEME_KEY]?->actions->delete();
            $body();
        } finally {
            Hilos::$db->settings[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]?->actions->delete();
            Hilos::$db->settings[ThemeSettingsCatalog::DEFAULT_THEME_KEY]?->actions->delete();
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
        }
    }
}
