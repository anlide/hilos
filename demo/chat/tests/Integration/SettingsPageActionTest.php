<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\Method\DTO\AuthMethodsSignalData;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Tables\Settings\DTO\HilosSettingAddActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingDeleteActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingResetActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingUpdateActionDTO;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration coverage for the settings page action layer (HIL-85).
 *
 * Drives {@see SettingsPage::onAction()} directly against the real catalog and DB:
 * the page routing + handler layer is not exercised by the actions-level
 * {@see SettingsBrowserStateTest}. Success cases assert the resulting DB state.
 *
 * Since HIL-946 an action is two steps, and so is a case here: the page checks the caller and
 * sends a frame, {@see SettingsLibraryAgent} writes the row and answers. So the fixture carries
 * the frame across ({@see self::submit()}) and both halves run in this one process — which is
 * exactly what the seam needs proving over a real database, and the reason this file was not
 * left asserting a write the page no longer performs.
 *
 * The refusals moved with the write, and their shape moved with them: what the page still
 * throws is what it can judge on its own — an empty key, a payload of the wrong type, an
 * unknown action — and what depends on a row now comes back as the sentence the library put in
 * its answer. Same words, other door.
 */
final class SettingsPageActionTest extends IntegrationTestCase
{
    private const string SETTINGS_AGENT_ID = 'test-settings-page-action-agent';

    /** @var string Catalog key (type string) used for add/update/delete-guard cases. */
    private const string CATALOG_KEY = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;

    /** @var string A second catalog key (no seeded override) for the update-missing case. */
    private const string UNSEEDED_CATALOG_KEY = SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER;

    protected function setUp(): void
    {
        parent::setUp();

        // The harness runs no worker, so nothing has queued the router the page hands its frame to.
        Hilos::$sr = new SignalRouter();
    }

    public function testAddActionCreatesCatalogOverride(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);

            $this->submit(
                'add-ok-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'from-page-action'),
            );

            $this->assertSame('from-page-action', Hilos::$db->settings[self::CATALOG_KEY]?->value);
        }, [self::CATALOG_KEY]);
    }

    public function testAddActionUpdatesExistingKeyInPlace(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);
            $this->submit(
                'add-dup-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'first'),
            );
            // Snapshot before the second add: the row must be updated, not replaced.
            $createdId = Hilos::$db->settings[self::CATALOG_KEY]?->id;

            $this->submit(
                'add-dup-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'second'),
            );

            $this->assertSame('second', Hilos::$db->settings[self::CATALOG_KEY]?->value);
            $this->assertSame($createdId, Hilos::$db->settings[self::CATALOG_KEY]?->id);
        }, [self::CATALOG_KEY]);
    }

    public function testUpdateActionChangesValue(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);
            $this->submit(
                'update-ok-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'before'),
            );

            $this->submit(
                'update-ok-ak',
                HilosSignalConstants::SETTING_UPDATE,
                new HilosSettingUpdateActionDTO(self::CATALOG_KEY, 'after'),
            );

            $this->assertSame('after', Hilos::$db->settings[self::CATALOG_KEY]?->value);
        }, [self::CATALOG_KEY]);
    }

    /**
     * Update on a key with no row writes it, and no longer refuses (HIL-946).
     *
     * The two buttons became one idempotent write when the write moved: putting a value under a
     * cataloged key is one thing to do to the collection, however the screen spells it. What is
     * lost is a refusal nobody could reach on purpose - the edit is only offered over a row that
     * is on screen - and what is gained is the answer to the case that IS reachable: a second
     * administrator reset the key between the click and the write, and the edit lands anyway
     * instead of failing on a race.
     */
    public function testUpdateActionOnAKeyWithoutARowWritesIt(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::UNSEEDED_CATALOG_KEY);

            $error = $this->submit(
                'update-missing-ak',
                HilosSignalConstants::SETTING_UPDATE,
                new HilosSettingUpdateActionDTO(self::UNSEEDED_CATALOG_KEY, '42'),
            );

            $this->assertNull($error);
            $this->assertSame('42', Hilos::$db->settings[self::UNSEEDED_CATALOG_KEY]?->value);
        }, [self::UNSEEDED_CATALOG_KEY]);
    }

    public function testDeleteActionRemovesOrphan(): void
    {
        $orphanKey = 'page_action_delete_orphan_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($orphanKey): void {
            $this->createOrphanSetting($orphanKey, 'to-remove');
            $this->assertNotNull(Hilos::$db->settings[$orphanKey]);

            $this->submit(
                'delete-orphan-ak',
                HilosSignalConstants::SETTING_DELETE,
                new HilosSettingDeleteActionDTO($orphanKey),
            );

            $this->assertNull(Hilos::$db->settings[$orphanKey]);
        }, [$orphanKey]);
    }

    public function testDeleteActionRejectsCatalogKey(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);
            $this->submit(
                'delete-catalog-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'override'),
            );

            $error = $this->submit(
                'delete-catalog-ak',
                HilosSignalConstants::SETTING_DELETE,
                new HilosSettingDeleteActionDTO(self::CATALOG_KEY),
            );

            $this->assertSame('Only orphan settings (not in catalog) can be deleted', $error);
            $this->assertNotNull(Hilos::$db->settings[self::CATALOG_KEY]);
        }, [self::CATALOG_KEY]);
    }

    public function testResetActionRemovesTheCatalogKeyRow(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);
            $this->submit(
                'reset-ok-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'to-reset'),
            );
            $this->assertNotNull(Hilos::$db->settings[self::CATALOG_KEY]);

            $this->submit(
                'reset-ok-ak',
                HilosSignalConstants::SETTING_RESET,
                new HilosSettingResetActionDTO(self::CATALOG_KEY),
            );

            // The row is gone, so the key reads as being on its catalog default again,
            // and the table row it leaves behind is the catalog placeholder (no id).
            $this->assertNull(Hilos::$db->settings[self::CATALOG_KEY]);
            $this->assertNull(Hilos::$table->settings->rowForKey(self::CATALOG_KEY)?->id);
        }, [self::CATALOG_KEY]);
    }

    public function testResetActionOnAKeyWithoutARowSucceeds(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::UNSEEDED_CATALOG_KEY);

            // Idempotent on purpose: a second admin may have reset first, and the race
            // must not turn into an error on this one's screen.
            $this->submit(
                'reset-absent-ak',
                HilosSignalConstants::SETTING_RESET,
                new HilosSettingResetActionDTO(self::UNSEEDED_CATALOG_KEY),
            );

            $this->assertNull(Hilos::$db->settings[self::UNSEEDED_CATALOG_KEY]);
        }, [self::UNSEEDED_CATALOG_KEY]);
    }

    public function testResetActionRejectsOrphan(): void
    {
        $orphanKey = 'page_action_reset_orphan_' . RandomHelper::hex(8);
        $this->withSettingsWriter(function () use ($orphanKey): void {
            $this->createOrphanSetting($orphanKey, 'not-resettable');

            // An orphan has no catalog default to return to. The screen never sends
            // this, but hiding a gesture is not securing it.
            $error = $this->submit(
                'reset-orphan-ak',
                HilosSignalConstants::SETTING_RESET,
                new HilosSettingResetActionDTO($orphanKey),
            );

            $this->assertSame(
                "Setting '{$orphanKey}' is an orphan and has no catalog default to reset to",
                $error,
            );
            $this->assertNotNull(Hilos::$db->settings[$orphanKey]);
        }, [$orphanKey]);
    }

    public function testAddActionRejectsEmptyKey(): void
    {
        $this->expectException(TableActionException::class);
        $this->settingsPage()->onAction(
            'empty-key-ak',
            HilosSignalConstants::SETTING_ADD,
            new HilosSettingAddActionDTO('', 'ignored'),
        );
    }

    public function testActionRejectsMismatchedPayloadType(): void
    {
        $this->expectException(InvalidActionPayloadException::class);
        $this->settingsPage()->onAction(
            'wrong-dto-ak',
            HilosSignalConstants::SETTING_ADD,
            new HilosSettingDeleteActionDTO(self::CATALOG_KEY),
        );
    }

    public function testUnknownActionThrows(): void
    {
        $this->expectException(AgentUnknownActionException::class);
        $this->settingsPage()->onAction(
            'unknown-ak',
            'setting_nonexistent_action',
            new HilosSettingDeleteActionDTO(self::CATALOG_KEY),
        );
    }

    /**
     * A write that changed the sign-in method set sends the new set to every connection, once (HIL-427).
     *
     * Through the general settings table on purpose: the screen of the methods is one door and
     * this is another, and the set has to reach the surfaces whichever of them moved it.
     */
    public function testAWriteThatChangedTheMethodSetSendsItToEveryConnection(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(AuthMethodSettings::DISABLED_KEY);

            $this->assertNull($this->submit(
                'methods-changed-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(AuthMethodSettings::DISABLED_KEY, AuthMethodKey::SMS),
            ));

            $frames = $this->methodSetFrames();
            $this->assertCount(1, $frames);
            $this->assertSame(SignalTypeConstants::WS_ALL_CONNECTED, $frames[0]->signalType->getType());
            $this->assertInstanceOf(WebSocketSignalData::class, $frames[0]->data);
            $this->assertInstanceOf(AuthMethodsSignalData::class, $frames[0]->data->data);
            $this->assertSame(EnabledAuthMethods::toWire(), $frames[0]->data->data->authMethods);
            $this->assertNotContains(AuthMethodKey::SMS, array_column($frames[0]->data->data->authMethods, 'key'));
        }, [AuthMethodSettings::DISABLED_KEY]);
    }

    /**
     * A write that left the method set as it was sends no set.
     */
    public function testAWriteThatLeftTheMethodSetAloneSendsNothing(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);

            $this->submit(
                'methods-unchanged-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'not-a-method'),
            );

            $this->assertSame([], $this->methodSetFrames());
        }, [self::CATALOG_KEY]);
    }

    /**
     * Switching every method off is refused by the setting's rule, and a refused write sends no set.
     */
    public function testSwitchingEveryMethodOffIsRefusedAndSendsNothing(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(AuthMethodSettings::DISABLED_KEY);

            $error = $this->submit(
                'methods-all-off-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(
                    AuthMethodSettings::DISABLED_KEY,
                    AuthMethodSettings::format(Hilos::authMethodDirectoryClass()::keys()),
                ),
            );

            $this->assertSame('At least one sign-in method must stay on', $error);
            $this->assertNull(Hilos::$db->settings[AuthMethodSettings::DISABLED_KEY]?->value);
            $this->assertSame([], $this->methodSetFrames());
        }, [AuthMethodSettings::DISABLED_KEY]);
    }

    /**
     * Runs one settings action end to end: the page checks and forwards, the library writes.
     *
     * Both halves in one process, which is what makes this a test of the seam and not of one
     * side of it. The frame is taken off the router the page queued it on and handed to the
     * library by hand, because a real router would carry it to another worker and there is
     * none here.
     *
     * @param string $acceptKey Connection accept key the action arrives on
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?string Sentence the library refused with, or null when the write went through
     */
    private function submit(string $acceptKey, string $action, ActionPayloadDTO $dto): ?string
    {
        $this->settingsPage()->onAction($acceptKey, $action, $dto);

        $asks = array_keys(SettingsLibraryAgent::AGENT_SIGNALS);
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            // The queue also carries the DB-sync frames the fixture's own writes announced;
            // what is wanted is the one frame the page addressed to the library.
            if (!in_array($signal->signalName->getName(), $asks, true)) {
                continue;
            }

            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            new SettingsLibraryAgent()->onSignalAgent($signal->data, 'agent', $signal->signalName->getName());

            return $this->answeredError();
        }

        $this->fail('The page owes the library a frame for every write it takes');
    }

    /**
     * Reads back the outcome the library sent to the page.
     *
     * @return ?string Sentence the write was refused with, or null when it went through
     */
    private function answeredError(): ?string
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                return $signal->data->data->error;
            }
        }

        $this->fail('An ask that arrived as a frame is answered or it hangs');
    }

    /**
     * Takes every queued sign-in method set off the router, leaving nothing behind.
     *
     * @return list<SignalDTO> Queued method-set frames in the order they were sent
     */
    private function methodSetFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_AUTH_METHODS) {
                $frames[] = $signal;
            }
        }

        return $frames;
    }

    /**
     * Builds a settings page bound to the Hilos index agent that owns it.
     *
     * @return SettingsPage Settings page under test
     */
    private function settingsPage(): SettingsPage
    {
        return new SettingsPage(new DemoHilosAgent());
    }

    /**
     * Creates an uncataloged persisted setting row for the delete-orphan case.
     *
     * @param string $key Orphan setting key
     * @param string $value Orphan setting value
     */
    private function createOrphanSetting(string $key, string $value): void
    {
        $setting = ObjectSetting::create();
        $setting->key = $key;
        $setting->type = SettingsCatalogConstants::TYPE_STRING;
        $setting->value = $value;
        $setting->sync();
    }

    /**
     * Deletes a persisted setting row when present.
     *
     * @param string $key Setting key
     */
    private function deleteSettingIfExists(string $key): void
    {
        Hilos::$db->settings[$key]?->actions->delete();
    }

    /**
     * Registers the settings collection writer, runs the test body, then cleans up any
     * rows it may have written and releases the writer.
     *
     * @param callable():void $body Test body run while the settings writer is held
     * @param list<string> $keysToCleanup Setting keys to delete afterward when present
     */
    private function withSettingsWriter(callable $body, array $keysToCleanup): void
    {
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::SETTINGS_AGENT_ID);

        try {
            $body();
        } finally {
            foreach ($keysToCleanup as $key) {
                $this->deleteSettingIfExists($key);
            }
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
        }
    }
}
