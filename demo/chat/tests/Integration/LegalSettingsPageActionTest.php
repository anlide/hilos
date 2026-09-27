<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Legal\LegalSettingsPage;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Legal\LegalSettings;
use Hilos\Pages\Legal\DTO\HilosLegalSettingSetActionDTO;

/** Legal settings stay owned by the settings library and checked by the project catalog. */
final class LegalSettingsPageActionTest extends IntegrationTestCase
{
    private const string WRITER = 'legal-settings-test';

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::$sr = new SignalRouter();
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::WRITER);
        foreach (LegalSettings::KEYS as $key) {
            Hilos::$db->settings[$key]?->actions->delete();
        }
    }

    protected function tearDown(): void
    {
        foreach (LegalSettings::KEYS as $key) {
            Hilos::$db->settings[$key]?->actions->delete();
        }
        TruthSourceRegistry::unregisterAgent(self::WRITER);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testBothSettingsAreWrittenByTheirLibraryAndReadThroughTheCatalog(): void
    {
        self::assertSame('checkbox', LegalSettings::consentForm());
        self::assertSame('freeze', LegalSettings::refusal());
        self::assertNull($this->submit(LegalSettings::CONSENT_FORM_KEY, 'line'));
        self::assertSame('line', LegalSettings::consentForm());
        self::assertNull($this->submit(LegalSettings::REFUSAL_KEY, 'remind'));
        self::assertSame('remind', LegalSettings::refusal());
    }

    public function testCatalogRuleRefusesAnUnknownValueWithoutChangingTheSetting(): void
    {
        self::assertNull($this->submit(LegalSettings::CONSENT_FORM_KEY, 'line'));
        self::assertNotNull($this->submit(LegalSettings::CONSENT_FORM_KEY, 'invalid'));
        self::assertSame('line', LegalSettings::consentForm());
        self::assertNotNull($this->submit(LegalSettings::REFUSAL_KEY, 'invalid'));
        self::assertSame('freeze', LegalSettings::refusal());
    }

    public function testThePageRefusesAKeyOutsideTheLegalSection(): void
    {
        $this->expectException(TableActionException::class);
        new LegalSettingsPage(new DemoHilosLegalAgent())->onAction(
            'legal-settings-ak', HilosSignalConstants::LEGAL_SETTING_SET,
            new HilosLegalSettingSetActionDTO('unrelated.setting', 'line'),
        );
    }

    /**
     * @param string $key Legal setting to write
     * @param string $value Requested value
     * @return ?string Library refusal, or null on success
     */
    private function submit(string $key, string $value): ?string
    {
        new LegalSettingsPage(new DemoHilosLegalAgent())->onAction(
            'legal-settings-ak', HilosSignalConstants::LEGAL_SETTING_SET, new HilosLegalSettingSetActionDTO($key, $value),
        );
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_SETTING_WRITE) {
                continue;
            }
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            new SettingsLibraryAgent()->onSignalAgent($signal->data, 'agent', $signal->signalName->getName());
            while (($answer = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($answer->data instanceof AgentSignalData && $answer->data->data instanceof HandoverAnswerSignalData) {
                    self::assertSame(HilosSignalConstants::HILOS_LEGAL_SETTING_WRITE_DONE, $answer->signalName->getName());
                    return $answer->data->data->error;
                }
            }
            self::fail('The settings library must answer its handover');
        }
        self::fail('The page must forward the legal setting to its owner');
    }
}
