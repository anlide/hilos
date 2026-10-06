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
use Hilos\Auth\Method\PasskeyAddressPolicy;
use Hilos\Auth\Library\DTO\AuthSecondFactorTrustDaysApplySignalData;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\Verification\DTO\CodeDeliverySignalData;
use Hilos\Constants\EnvConstants;
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
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\DTO\SettingPresetApplySignalData;
use Hilos\Database\Settings\Preset\SettingPreset;
use Hilos\Database\Settings\Preset\SettingPresetGroup;
use Hilos\Database\Settings\Preset\SettingPresetGroupProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Tables\Settings\DTO\HilosSettingAddActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingDeleteActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingResetActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingUpdateActionDTO;
use Hilos\Tables\Settings\HilosSettingTableRow;
use Hilos\Theme\ThemeSettingsCatalog;
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

    /** Both theme keys use the existing page, writer and catalog placeholder rows. */
    public function testThemeSettingsCanBeAddedUpdatedAndReset(): void
    {
        $switchKey = ThemeSettingsCatalog::SWITCHING_ENABLED_KEY;
        $defaultKey = ThemeSettingsCatalog::DEFAULT_THEME_KEY;

        $this->withSettingsWriter(function () use ($switchKey, $defaultKey): void {
            $this->deleteSettingIfExists($switchKey);
            $this->deleteSettingIfExists($defaultKey);

            $this->assertTrue(Hilos::$setting[$switchKey]->bool());
            $this->assertSame(ThemeSettingsCatalog::SYSTEM, Hilos::$setting[$defaultKey]->string());
            foreach ([$switchKey => '1', $defaultKey => ThemeSettingsCatalog::SYSTEM] as $key => $default) {
                $row = Hilos::$table->settings->rowForKey($key);
                $this->assertNull($row?->id);
                $this->assertSame($default, $row?->value);
                $this->assertSame($default, $row?->defaultValue);
                $this->assertNull($row?->overrideValue);
                $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_DEFAULT, $row?->valueSource);
            }

            $this->assertNull($this->submit('theme-switch-ak', HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO($switchKey, false)));
            $this->assertNull($this->submit('theme-default-ak', HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO($defaultKey, ThemeSettingsCatalog::DARK)));
            $this->assertFalse(Hilos::$setting[$switchKey]->bool());
            $this->assertSame(ThemeSettingsCatalog::DARK, Hilos::$setting[$defaultKey]->string());
            $this->assertSame('0', Hilos::$db->settings[$switchKey]?->value);
            $this->assertSame(ThemeSettingsCatalog::DARK, Hilos::$db->settings[$defaultKey]?->value);
            foreach ([$switchKey => '0', $defaultKey => ThemeSettingsCatalog::DARK] as $key => $override) {
                $row = Hilos::$table->settings->rowForKey($key);
                $this->assertNotNull($row?->id);
                $this->assertSame($override, $row?->value);
                $this->assertSame($override, $row?->overrideValue);
                $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_OVERRIDE, $row?->valueSource);
            }

            $this->assertNull($this->submit('theme-switch-ak', HilosSignalConstants::SETTING_UPDATE,
                new HilosSettingUpdateActionDTO($switchKey, true)));
            $this->assertNull($this->submit('theme-default-ak', HilosSignalConstants::SETTING_UPDATE,
                new HilosSettingUpdateActionDTO($defaultKey, ThemeSettingsCatalog::LIGHT)));
            $this->assertTrue(Hilos::$setting[$switchKey]->bool());
            $this->assertSame(ThemeSettingsCatalog::LIGHT, Hilos::$setting[$defaultKey]->string());
            $this->assertSame('1', Hilos::$db->settings[$switchKey]?->value);
            $this->assertSame(ThemeSettingsCatalog::LIGHT, Hilos::$db->settings[$defaultKey]?->value);

            $this->assertNull($this->submit('theme-switch-ak', HilosSignalConstants::SETTING_RESET,
                new HilosSettingResetActionDTO($switchKey)));
            $this->assertNull($this->submit('theme-default-ak', HilosSignalConstants::SETTING_RESET,
                new HilosSettingResetActionDTO($defaultKey)));
            $this->assertNull(Hilos::$db->settings[$switchKey]);
            $this->assertNull(Hilos::$db->settings[$defaultKey]);
            $this->assertTrue(Hilos::$setting[$switchKey]->bool());
            $this->assertSame(ThemeSettingsCatalog::SYSTEM, Hilos::$setting[$defaultKey]->string());
            foreach (ThemeSettingsCatalog::KEYS as $key) {
                $row = Hilos::$table->settings->rowForKey($key);
                $this->assertNull($row?->id);
                $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_DEFAULT, $row?->valueSource);
            }
        }, ThemeSettingsCatalog::KEYS);
    }

    /** Invalid defaults are refused before either creating a row or changing one. */
    public function testThemeDefaultRefusalPreservesTheStoredValue(): void
    {
        $key = ThemeSettingsCatalog::DEFAULT_THEME_KEY;

        $this->withSettingsWriter(function () use ($key): void {
            $this->deleteSettingIfExists($key);
            $this->assertSame('Choose light, dark or system', $this->submit(
                'theme-invalid-ak', HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO($key, 'blue'),
            ));
            $this->assertNull(Hilos::$db->settings[$key]);
            $this->assertSame(ThemeSettingsCatalog::SYSTEM, Hilos::$setting[$key]->string());

            $this->assertNull($this->submit('theme-valid-ak', HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO($key, ThemeSettingsCatalog::DARK)));
            $this->assertSame('Choose light, dark or system', $this->submit(
                'theme-invalid-ak', HilosSignalConstants::SETTING_UPDATE,
                new HilosSettingUpdateActionDTO($key, 'Dark'),
            ));
            $this->assertSame(ThemeSettingsCatalog::DARK, Hilos::$db->settings[$key]?->value);
            $this->assertSame(ThemeSettingsCatalog::DARK, Hilos::$setting[$key]->string());
        }, [$key]);
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

            $frames = $this->framesNamed(HilosSignalConstants::HILOS_AUTH_METHODS);
            $this->assertCount(1, $frames);
            $this->assertSame(SignalTypeConstants::WS_ALL_CONNECTED, $frames[0]->signalType->getType());
            $this->assertInstanceOf(WebSocketSignalData::class, $frames[0]->data);
            $this->assertInstanceOf(AuthMethodsSignalData::class, $frames[0]->data->data);
            $this->assertSame(EnabledAuthMethods::toWire(), $frames[0]->data->data->authMethods);
            $this->assertNotContains(AuthMethodKey::SMS, array_column($frames[0]->data->data->authMethods, 'key'));
        }, [AuthMethodSettings::DISABLED_KEY]);
    }

    /**
     * A write that moved only the passkey policy sends the method-set frame, once, carrying it (HIL-1105).
     *
     * Through the general settings table, for the reason the case above gives: the policy
     * rides the frame of the set, and the frame has to go whichever door moved it.
     */
    public function testAWriteThatMovedThePasskeyPolicySendsTheFrameWithIt(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(PasskeyAddressPolicy::SETTING_KEY);

            $this->assertNull($this->submit(
                'passkey-policy-changed-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(PasskeyAddressPolicy::SETTING_KEY, false),
            ));

            $frames = $this->framesNamed(HilosSignalConstants::HILOS_AUTH_METHODS);
            $this->assertCount(1, $frames);
            $this->assertSame(SignalTypeConstants::WS_ALL_CONNECTED, $frames[0]->signalType->getType());
            $this->assertInstanceOf(WebSocketSignalData::class, $frames[0]->data);
            $this->assertInstanceOf(AuthMethodsSignalData::class, $frames[0]->data->data);
            $this->assertFalse($frames[0]->data->data->passkeyAllowsUnproven);
            $this->assertSame(EnabledAuthMethods::toWire(), $frames[0]->data->data->authMethods);
        }, [PasskeyAddressPolicy::SETTING_KEY]);
    }

    /**
     * Writing the passkey policy it already holds changes nothing and sends no frame.
     */
    public function testWritingThePasskeyPolicyItAlreadyHoldsSendsNothing(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(PasskeyAddressPolicy::SETTING_KEY);

            $this->assertNull($this->submit(
                'passkey-policy-unchanged-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(PasskeyAddressPolicy::SETTING_KEY, true),
            ));

            $this->assertSame([], $this->framesNamed(HilosSignalConstants::HILOS_AUTH_METHODS));
        }, [PasskeyAddressPolicy::SETTING_KEY]);
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

            $this->assertSame([], $this->framesNamed(HilosSignalConstants::HILOS_AUTH_METHODS));
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
            $this->assertSame([], $this->framesNamed(HilosSignalConstants::HILOS_AUTH_METHODS));
        }, [AuthMethodSettings::DISABLED_KEY]);
    }

    /**
     * A gateway setting written through the general table reaches every connection (HIL-1102).
     */
    public function testAWriteThatChangesDeliverySendsOneFrameToEveryConnection(): void
    {
        $key = DeliveryChannelSettings::fieldKey(SmsDeliveryChannel::NAME, SmsDeliveryChannel::FIELD_ENDPOINT_URL);
        $previous = $this->overrideEnv([
            EnvConstants::SMS_PROVIDER->name => '',
            EnvConstants::SMS_ENDPOINT_URL->name => '',
            EnvConstants::TELEGRAM_GATEWAY_TOKEN->name => '',
        ]);

        try {
            $this->withSettingsWriter(function () use ($key): void {
                $this->deleteSettingIfExists($key);
                $this->assertFalse(CodeDeliverySignalData::current()->codeDelivery[CodeDeliverySignalData::phone]);

                $this->assertNull($this->submit(
                    'delivery-changed-ak',
                    HilosSignalConstants::SETTING_ADD,
                    new HilosSettingAddActionDTO($key, 'https://stand-gateway:18000/sms/send'),
                ));

                $frames = $this->framesNamed(HilosSignalConstants::HILOS_CODE_DELIVERY);
                $this->assertCount(1, $frames);
                $this->assertSame(SignalTypeConstants::WS_ALL_CONNECTED, $frames[0]->signalType->getType());
                $this->assertInstanceOf(WebSocketSignalData::class, $frames[0]->data);
                $this->assertInstanceOf(CodeDeliverySignalData::class, $frames[0]->data->data);
                $this->assertTrue($frames[0]->data->data->codeDelivery[CodeDeliverySignalData::phone]);
                $this->assertSame(CodeDeliverySignalData::current()->toArray(), $frames[0]->data->data->toArray());

                $this->assertNull($this->submit(
                    'delivery-reset-ak',
                    HilosSignalConstants::SETTING_RESET,
                    new HilosSettingResetActionDTO($key),
                ));
                $frames = $this->framesNamed(HilosSignalConstants::HILOS_CODE_DELIVERY);
                $this->assertCount(1, $frames);
                $this->assertFalse($frames[0]->data->data->codeDelivery[CodeDeliverySignalData::phone]);
            }, [$key]);
        } finally {
            $this->restoreEnv($previous);
        }
    }

    /** A settings write unrelated to delivery owes no delivery frame. */
    public function testAWriteThatLeavesDeliveryAloneSendsNoDeliveryFrame(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(self::CATALOG_KEY);
            $this->assertNull($this->submit(
                'delivery-unchanged-ak',
                HilosSignalConstants::SETTING_ADD,
                new HilosSettingAddActionDTO(self::CATALOG_KEY, 'not-a-channel'),
            ));

            $this->assertSame([], $this->framesNamed(HilosSignalConstants::HILOS_CODE_DELIVERY));
        }, [self::CATALOG_KEY]);
    }

    /**
     * A shorter term is handed to the trust owner; the settings writer does not answer first.
     */
    public function testShorterTrustTermDefersTheAnswer(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(SecondFactorSettings::TRUST_DAYS_KEY);
            new SettingsLibraryAgent()->onSignalAgent(
                new AgentSignalData(new SettingWriteSignalData(
                    replySignal: 'trust-term-setting-done',
                    acceptKey: 'trust-term-ak',
                    requestId: 'trust-term-request',
                    action: HilosSignalConstants::SETTING_ADD,
                    successMessage: 'Trust term saved.',
                    key: SecondFactorSettings::TRUST_DAYS_KEY,
                    value: 7,
                )),
                'test',
                HilosSignalConstants::HILOS_SETTING_WRITE,
            );

            $apply = [];
            $answered = [];
            while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
                if ($signal->data instanceof AgentSignalData
                    && $signal->data->data instanceof AuthSecondFactorTrustDaysApplySignalData) {
                    $apply[] = $signal->data->data;
                }
                if ($signal->data instanceof AgentSignalData
                    && $signal->data->data instanceof HandoverAnswerSignalData) {
                    $answered[] = $signal->data->data;
                }
            }
            $this->assertCount(1, $apply);
            $this->assertSame(7, $apply[0]->trustDays);
            $this->assertSame('trust-term-setting-done', $apply[0]->replySignal);
            $this->assertSame([], $answered);
        }, [SecondFactorSettings::TRUST_DAYS_KEY]);
    }

    /** A reset can shorten an override, while raising a zero term cannot restore erased trusts. */
    public function testResetAndIncreaseRouteOnlyShorterTrustTerms(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(SecondFactorSettings::TRUST_DAYS_KEY);
            $write = function (int $days): void {
                new SettingsLibraryAgent()->onSignalAgent(
                    new AgentSignalData(new SettingWriteSignalData(
                        replySignal: 'trust-term-setting-done',
                        acceptKey: 'trust-term-ak',
                        requestId: 'trust-term-request',
                        action: HilosSignalConstants::SETTING_ADD,
                        successMessage: null,
                        key: SecondFactorSettings::TRUST_DAYS_KEY,
                        value: $days,
                    )),
                    'test',
                    HilosSignalConstants::HILOS_SETTING_WRITE,
                );
            };

            $write(365);
            $this->assertSame([], $this->framesNamed(HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY));
            new SettingsLibraryAgent()->onSignalAgent(
                new AgentSignalData(new SettingResetSignalData(
                    replySignal: 'trust-term-setting-done',
                    acceptKey: 'trust-term-ak',
                    requestId: 'trust-term-request',
                    action: HilosSignalConstants::SETTING_RESET,
                    successMessage: null,
                    key: SecondFactorSettings::TRUST_DAYS_KEY,
                )),
                'test',
                HilosSignalConstants::HILOS_SETTING_RESET,
            );
            $reset = $this->framesNamed(HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY);
            $this->assertCount(1, $reset);
            $this->assertSame(SecondFactorSettings::DEFAULT_TRUST_DAYS, $reset[0]->data->data->trustDays);

            $write(0);
            $zero = $this->framesNamed(HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY);
            $this->assertCount(1, $zero);
            $this->assertSame(0, $zero[0]->data->data->trustDays);
            $write(0);
            $this->assertCount(1, $this->framesNamed(HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY));
            $write(365);
            $this->assertSame([], $this->framesNamed(HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY));
        }, [SecondFactorSettings::TRUST_DAYS_KEY]);
    }

    /** Applying a preset that contains the trust term still waits for the holder. */
    public function testPresetWithTrustTermDefersTheAnswer(): void
    {
        $this->withSettingsWriter(function (): void {
            $this->deleteSettingIfExists(SecondFactorSettings::TRUST_DAYS_KEY);
            $this->deleteSettingIfExists(self::CATALOG_KEY);
            new SettingsLibraryAgent()->onSignalAgent(
                new AgentSignalData(new SettingPresetApplySignalData(
                    replySignal: 'trust-term-preset-done',
                    acceptKey: 'trust-term-ak',
                    requestId: 'trust-term-request',
                    action: HilosSignalConstants::SETTING_PRESET_APPLY,
                    successMessage: null,
                    groupProvider: TrustTermTestPreset::class,
                    preset: TrustTermTestPreset::SHORT,
                )),
                'test',
                HilosSignalConstants::HILOS_SETTING_PRESET_APPLY,
            );

            $apply = [];
            $answers = [];
            while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
                if ($signal->data instanceof AgentSignalData
                    && $signal->data->data instanceof AuthSecondFactorTrustDaysApplySignalData) {
                    $apply[] = $signal->data->data;
                }
                if ($signal->data instanceof AgentSignalData
                    && $signal->data->data instanceof HandoverAnswerSignalData) {
                    $answers[] = $signal->data->data;
                }
            }
            $this->assertCount(1, $apply);
            $this->assertSame(7, $apply[0]->trustDays);
            $this->assertSame([], $answers);
        }, [SecondFactorSettings::TRUST_DAYS_KEY, self::CATALOG_KEY]);
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
     * Drains the queue once, selecting the frame being asserted even when other broadcasts follow the write.
     *
     * @param string $name Signal name to collect
     * @return list<SignalDTO> Matching frames in the order they were sent
     */
    private function framesNamed(string $name): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                $frames[] = $signal;
            }
        }

        return $frames;
    }

    /**
     * Sets process environment variables and returns what they held before.
     *
     * @param array<string, string> $values Variable name to the value this case needs
     * @return array<string, string|false> Variable name to its previous value, false when it was unset
     */
    private function overrideEnv(array $values): array
    {
        $previous = [];
        foreach ($values as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        return $previous;
    }

    /**
     * Puts back the process environment {@see self::overrideEnv()} changed.
     *
     * @param array<string, string|false> $previous Variable name to its previous value, false when it was unset
     */
    private function restoreEnv(array $previous): void
    {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
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

/** Fixture preset containing the trust term and using the catalog's string stub as selection. */
final class TrustTermTestPreset implements SettingPresetGroupProviderInterface
{
    public const string SHORT = 'short';

    /** @return SettingPresetGroup One preset that shortens browser trust */
    public static function presetGroup(): SettingPresetGroup
    {
        return new SettingPresetGroup(
            'trust-term-test',
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING,
            [new SettingPreset(self::SHORT, [SecondFactorSettings::TRUST_DAYS_KEY => 7])],
        );
    }
}
