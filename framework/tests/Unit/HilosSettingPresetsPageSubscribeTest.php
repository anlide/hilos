<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\Preset\SettingPresetChangeSubscriber;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogSettingsPresets;
use Hilos\Pages\DTO\HilosSettingPresetsSignalData;
use Hilos\Pages\Logs\AbstractHilosLogsSettingsPage;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the screen the logging modes are chosen on (HIL-762).
 *
 * The subject is the arrangement rather than the recipe: a subscription is answered with the
 * state of the group, and after that the state travels on the EVENT of a settings write rather
 * than on a clock. Two of these cases are here because the first cut of that arrangement got
 * them wrong.
 *
 * A browser arriving must not cancel an update owed to the browsers already on the page. The
 * newcomer is answered personally, so writing the broadcast's own bookkeeping — the fingerprint
 * and the debt — while answering it would silence the very push it was about to be part of.
 *
 * A rebuild that fails leaves the debt standing. Forgiven up front, a settings read that failed
 * once would leave the page marked fresh while showing what it showed before, until some later
 * write happened to mark it again.
 */
final class HilosSettingPresetsPageSubscribeTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-presets-1';

    /** A second connection, for the case where one admin's arrival must not silence another's push. */
    private const string SECOND_ACCEPT_KEY = 'ak-presets-2';

    private ?SettingsAccessor $previousSetting = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSetting = Hilos::$setting;
        SettingPresetsPageTestAccessor::$values = [];
        Hilos::$setting = new SettingPresetsPageTestAccessor(LogSettingsCatalog::class);

        Hilos::$sr = new SignalRouter();
        Hilos::initBrowser();
    }

    protected function tearDown(): void
    {
        // The subscriber set is static and outlives the test; a key left in it would push at
        // nobody for the rest of the suite.
        SettingPresetsPageTestPage::removeSubscriber(self::ACCEPT_KEY);
        SettingPresetsPageTestPage::removeSubscriber(self::SECOND_ACCEPT_KEY);

        Hilos::$setting = $this->previousSetting;
        SettingPresetsPageTestAccessor::$values = [];
        Hilos::$sr = null;
        Hilos::$browser = null;

        parent::tearDown();
    }

    /**
     * Two answers, in this order and no other: the state of the group first, because the frame
     * behind it means the subscription is answered in full.
     */
    public function testTheSubscriptionAnswersWithTheGroupStateAndThenTheFrame(): void
    {
        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(
            [
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_SETTINGS,
                SignalTypeConstants::PAGE_RESPONSE,
            ],
            $this->queuedSignalNames(),
        );
    }

    public function testTheStateCarriesTheAppliedModeAndTheOneMemberThatDrifted(): void
    {
        $this->applyNormalWithOneDrift();

        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $state = $this->groupState();

        $this->assertSame(LogSettingsPresets::GROUP, $state->group);
        $this->assertSame(LogSettingsPresets::NORMAL, $state->selected);
        $this->assertCount(3, $state->presets);
        $this->assertCount(1, $state->differences);
        $this->assertSame(LogSettingsCatalog::WRITE_LEVEL, $state->differences[0]->key);
    }

    public function testATickPushesNothingWhileNoSettingsWriteHasBeenAnnounced(): void
    {
        $this->subscribeAndDrain(self::ACCEPT_KEY);

        $this->assertSame([], $this->tickTargets());
    }

    public function testASettingsWriteReachesEverySubscriberOnTheNextTick(): void
    {
        $this->subscribeAndDrain(self::ACCEPT_KEY);
        $this->subscribeAndDrain(self::SECOND_ACCEPT_KEY);

        $this->announceASettingsWrite();

        $this->assertSame([self::ACCEPT_KEY, self::SECOND_ACCEPT_KEY], $this->tickTargets());
    }

    /**
     * The regression the arrangement was rewritten for: the newcomer is answered personally, and
     * the push owed to the admin already on the page happens all the same.
     */
    public function testASecondAdminArrivingDoesNotSilenceTheUpdateOwedToTheFirst(): void
    {
        $this->subscribeAndDrain(self::ACCEPT_KEY);
        $this->announceASettingsWrite();
        $this->applyNormalWithOneDrift();

        $this->subscribeAndDrain(self::SECOND_ACCEPT_KEY);

        $this->assertContains(self::ACCEPT_KEY, $this->tickTargets());
    }

    /**
     * The other regression: a rebuild that could not read the settings owes the same push next
     * tick, rather than counting itself done.
     */
    public function testARebuildThatFailedStillOwesThePushOnTheNextTick(): void
    {
        $this->subscribeAndDrain(self::ACCEPT_KEY);
        $this->announceASettingsWrite();
        $this->applyNormalWithOneDrift();

        $accessor = Hilos::$setting;
        Hilos::$setting = null;
        try {
            SettingPresetsPageTestPage::onAgentTick(new SettingPresetsPageTestAgent());
            $this->fail('A tick with no settings accessor cannot rebuild the state');
        } catch (SettingAccessorUnavailableException) {
            Hilos::$setting = $accessor;
        }

        $this->assertSame([self::ACCEPT_KEY], $this->tickTargets());
    }

    public function testAViewerWhoLeftIsNotPushedAt(): void
    {
        $this->subscribeAndDrain(self::ACCEPT_KEY);
        $this->page()->onUnsubscribe(self::ACCEPT_KEY);

        $this->announceASettingsWrite();

        $this->assertSame([], $this->tickTargets());
    }

    public function testAWriteToAnotherCollectionOwesNothing(): void
    {
        $this->subscribeAndDrain(self::ACCEPT_KEY);

        new SettingPresetChangeSubscriber()->onSourceChange(
            SourceChange::dbUpdated('users', '1', []),
            SourceChangeProvenance::LocalWrite,
        );

        $this->assertSame([], $this->tickTargets());
    }

    /**
     * Builds the page under test, a project's own stand-in adding nothing of its own.
     *
     * @return SettingPresetsPageTestPage Page bound to a test agent
     */
    private function page(): SettingPresetsPageTestPage
    {
        return new SettingPresetsPageTestPage(new SettingPresetsPageTestAgent());
    }

    /**
     * Puts the installation on the middle mode with one member edited away from it.
     *
     * Every other member is scripted to what the mode declares, because an unscripted one falls
     * back to the environment and would show up as a difference of its own - which is the honest
     * answer on a fresh installation, and one difference too many to read a case by.
     */
    private function applyNormalWithOneDrift(): void
    {
        $preset = LogSettingsPresets::presetGroup()->presetByName(LogSettingsPresets::NORMAL);
        $this->assertNotNull($preset, 'The recipe declares the middle mode');

        SettingPresetsPageTestAccessor::$values = [
            ...$preset->values,
            LogSettingsCatalog::PRESET => LogSettingsPresets::NORMAL,
            LogSettingsCatalog::WRITE_LEVEL => 'DEBUG',
        ];
    }

    /**
     * Announces a settings write the way the source bus does, through the subscriber itself.
     */
    private function announceASettingsWrite(): void
    {
        new SettingPresetChangeSubscriber()->onSourceChange(
            SourceChange::dbUpdated(HilosDbContext::settings, '1', []),
            SourceChangeProvenance::LocalWrite,
        );
    }

    /**
     * Subscribes a connection and throws away what it was answered with.
     *
     * @param string $acceptKey Connection accept key
     */
    private function subscribeAndDrain(string $acceptKey): void
    {
        $this->page()->onSubscribe($acceptKey, new PageRouteParams([]));
        $this->queuedSignalNames();
    }

    /**
     * Runs one tick and reports which connections it pushed the state to.
     *
     * @return list<string> Accept keys the tick addressed, in the order it addressed them
     */
    private function tickTargets(): array
    {
        SettingPresetsPageTestPage::onAgentTick(new SettingPresetsPageTestAgent());

        $targets = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertSame(
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_SETTINGS,
                $signal->signalName->getName(),
            );
            $targets[] = (string)$signal->data->targetAcceptKey;
        }

        return $targets;
    }

    /**
     * Takes the group state the page answered a subscription with.
     *
     * @return HilosSettingPresetsSignalData Payload of the first queued signal
     */
    private function groupState(): HilosSettingPresetsSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The subscription answers with the state of the group');
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(HilosSettingPresetsSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Drains the queue into the signal names it holds.
     *
     * @return list<string> Queued signal names, oldest first
     */
    private function queuedSignalNames(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }
}

/**
 * Concrete stand-in for a project's logging-modes page, which adds nothing of its own.
 */
final class SettingPresetsPageTestPage extends AbstractHilosLogsSettingsPage
{
}

/**
 * Minimal page agent providing a signal source for sendToUser and for the tick push.
 */
final class SettingPresetsPageTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_logs';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'hilos_logs');
    }
}

/**
 * Settings accessor answering with scripted persisted values instead of reading a database.
 */
final class SettingPresetsPageTestAccessor extends SettingsAccessor
{
    /** @var array<string, mixed> Persisted value by key; a key without one falls back to the catalog default */
    public static array $values = [];

    /**
     * Returns the scripted value for a key, or the catalog default when none is scripted.
     *
     * @param string $key Setting key
     * @return mixed Scripted persisted value, or the resolved catalog default
     * @throws DatabaseException When the persisted setting lookup of the parent accessor fails
     * @throws SettingException When the key or its default reference is invalid
     */
    public function effectiveValueFor(string $key): mixed
    {
        return self::$values[$key] ?? parent::effectiveValueFor($key);
    }
}
