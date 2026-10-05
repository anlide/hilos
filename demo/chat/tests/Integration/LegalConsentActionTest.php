<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\HilosException;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\DTO\LegalConsentActionDTO;
use Hilos\Auth\Library\DTO\LegalConsentReplyDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;

/** An anonymous consent read returns the project's real text and configured form. */
final class LegalConsentActionTest extends IntegrationTestCase
{
    private const string WRITER = 'legal-consent-setting-test';

    /**
     * Bind the real chat catalog and remove a setting override before each public read.
     *
     * @throws HilosException When the fixture, catalog or action cannot be evaluated
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::WRITER);
        Hilos::$db->settings[LegalSettings::CONSENT_FORM_KEY]?->actions->delete();
    }

    /**
     * Restore the consent form default and release the fixture writer.
     *
     * @throws HilosException When the fixture, catalog or action cannot be evaluated
     */
    protected function tearDown(): void
    {
        Hilos::$db->settings[LegalSettings::CONSENT_FORM_KEY]?->actions->delete();
        TruthSourceRegistry::unregisterAgent(self::WRITER);
        parent::tearDown();
    }

    /**
     * The public action needs no identity and returns the complete composed catalog.
     *
     * @throws HilosException When the fixture, catalog or action cannot be evaluated
     */
    public function testAReadWithoutASessionReturnsBothDocumentsAndTheirDeviations(): void
    {
        $reply = $this->usersLibrary()->onAgentAction('anonymous-consent', HilosSignalConstants::HILOS_LEGAL_CONSENT, new LegalConsentActionDTO());
        self::assertInstanceOf(LegalConsentReplyDTO::class, $reply);
        self::assertSame('checkbox', $reply->form);
        self::assertSame(['terms', 'privacy'], array_column($reply->documents, 'document'));
        self::assertCount(6, $reply->documents[0]['clauses']);
        self::assertCount(7, $reply->documents[1]['clauses']);
        self::assertSame(4, $reply->documents[0]['revision']['deviationCount']);
        self::assertSame(2, $reply->documents[1]['revision']['deviationCount']);
        foreach ($reply->documents as $document) {
            foreach ($document['clauses'] as $clause) {
                self::assertNotSame('', $clause['text']);
                self::assertNotSame('', $clause['standardStatement']);
            }
        }
        self::assertSame($reply->toArray(), LegalConsentReplyDTO::fromArray($reply->toArray())->toArray());
    }

    /**
     * The next entrance observes the persisted form choice.
     *
     * @throws HilosException When the fixture, catalog or action cannot be evaluated
     */
    public function testTheNextReadUsesTheStoredConsentForm(): void
    {
        Hilos::$db->settings->actions->add(LegalSettings::CONSENT_FORM_KEY, 'line', LegalSettingsCatalog::getCatalog());
        $reply = $this->usersLibrary()->onAgentAction('anonymous-consent', HilosSignalConstants::HILOS_LEGAL_CONSENT, new LegalConsentActionDTO());
        self::assertInstanceOf(LegalConsentReplyDTO::class, $reply);
        self::assertSame('line', $reply->form);
    }
}
