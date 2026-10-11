<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\DTO\LanguageCardSummary;
use Hilos\I18n\I18nTestCommandConstants;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageSwitchOnActionDTO;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Language switch-on, live countryless facts, and test setup commands share the i18n library. */
final class I18nLanguageSwitchOnTest extends IntegrationTestCase
{
    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
        foreach ([
            HilosDbContext::languages,
            HilosDbContext::countries,
            HilosDbContext::locales,
            HilosDbContext::languageNames,
            HilosDbContext::countryNames,
        ] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->agent->getId());
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), $this->agent->getId());
        Hilos::$rt->connections->actions->clear();
        $this->clearFixtures();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent($this->agent->getId());
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        putenv($this->previousDefaultLanguage === false
            ? EnvConstants::HILOS_DEFAULT_LANGUAGE->name
            : EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testSwitchOnWithoutLocaleIsAllowedAndIdempotent(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('fr', 'Français', false);
        });
        $page = new LanguageDetailPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_ON,
                new HilosI18nLanguageSwitchOnActionDTO('fr')));
        });
        self::assertTrue(Hilos::$db->languages['fr']->enabled);
        $page->sendActionSuccess('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_ON, 'switch-on-request');
        do {
            $success = Hilos::$sr->getNextQueuedSignal();
        } while ($success !== null && $success->signalName->getName() !== SignalConstants::ACTION_SUCCESS);
        self::assertSame(SignalConstants::ACTION_SUCCESS, $success?->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $success->data);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success->data->data);
        self::assertSame('Language switched on.', $success->data->data->message);
        self::assertNull($success->data->data->reply);
        self::assertSame(LanguageCardSummary::COUNTRYLESS_MISSING, Hilos::$db->languages->cardSummary(
            Hilos::$db->languages['fr']->id,
        )->countrylessLocale);
        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_ON,
                new HilosI18nLanguageSwitchOnActionDTO('fr')));
        });
        self::assertTrue(Hilos::$db->languages['fr']->enabled);

        $this->expectException(TableActionException::class);
        $page->onAction('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_ON,
            new HilosI18nLanguageSwitchOnActionDTO('zz'));
    }

    public function testBothOpenCardsReceiveEnabledAndCountrylessLocaleStates(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Switch-on card admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        foreach (['switch-on-one', 'switch-on-two'] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int)$admin->id, $session->token, (int)$session->id);
        }
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('fr', 'Français', false);
        });
        foreach (['switch-on-one', 'switch-on-two'] as $acceptKey) {
            $this->subscribe($acceptKey, 'fr');
            $responses = $this->drainCardResponses();
            self::assertCount(1, $responses);
            self::assertSame(LanguageCardSummary::COUNTRYLESS_MISSING, $this->cardOf($responses[0])['summary']['countrylessLocale']);
        }

        $language = Hilos::$db->languages['fr'];
        $this->underAgent($this->agent, function (): void {
            new LanguageDetailPage($this->agent)->onAction('switch-on-one', HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_ON,
                new HilosI18nLanguageSwitchOnActionDTO('fr'));
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::languages, (string)$language->id, [EntityLanguage::enabled => true],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(true, LanguageCardSummary::COUNTRYLESS_MISSING);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->locales->actions->create(
                Hilos::$db->languages['fr'], null, 'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
                'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
            );
        });
        $locale = Hilos::$db->locales['fr'];
        Hilos::$browser->record(SourceChange::dbCreated(
            HilosDbContext::locales, (string)$locale->id, [EntityLocale::language_id => $language->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(true, LanguageCardSummary::COUNTRYLESS_OFF);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->locales['fr']->actions->switchOn();
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::locales, (string)$locale->id,
            [EntityLocale::enabled => true, EntityLocale::language_id => $language->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(true, LanguageCardSummary::COUNTRYLESS_ON);
    }

    public function testSetupCommandsHandleRepeatsAndRefusals(): void
    {
        $this->assertCommand(CliCommands::I18N_TEST_LANGUAGE_OFF, 'de', true, ['code' => 'de', 'enabled' => false]);
        self::assertFalse(Hilos::$db->languages['de']->enabled);
        $this->assertCommand(CliCommands::I18N_TEST_LANGUAGE_OFF, 'de', true, ['code' => 'de', 'enabled' => false]);
        $this->assertCommand(CliCommands::I18N_TEST_LANGUAGE_OFF, 'en', false,
            "Default language 'en' cannot be switched off");
        $this->assertCommand(CliCommands::I18N_TEST_LANGUAGE_OFF, 'zz', false,
            "Unknown built-in language code 'zz'");

        $this->assertCommand(CliCommands::I18N_TEST_LANGUAGE_OFF, 'es', true, ['code' => 'es', 'enabled' => false]);
        $this->assertCommand(CliCommands::I18N_TEST_LOCALE_ON, 'es', true, ['code' => 'es', 'enabled' => true]);
        self::assertTrue(Hilos::$db->locales['es']->enabled);
        $this->assertCommand(CliCommands::I18N_TEST_LOCALE_ON, 'es', true, ['code' => 'es', 'enabled' => true]);
        $this->assertCommand(CliCommands::I18N_TEST_LOCALE_ON, 'de', false,
            "The built-in catalog has no locale without a country for 'de'");
        $this->assertCommand(CliCommands::I18N_TEST_LOCALE_ON, 'qq', false,
            "Language 'qq' does not exist");
    }

    /** @param array<string, mixed>|string $expected Successful reply payload or refusal message */
    private function assertCommand(string $command, string $code, bool $ok, array|string $expected): void
    {
        $request = new CommandRequestDTO($command . $code, $command, [I18nTestCommandConstants::FIELD_CODE => $code]);
        $this->underAgent($this->agent, function () use ($request): void {
            $this->agent->onSignalCommand($request, '', '');
        });
        $reply = $this->drainCommandReply();
        self::assertSame($ok, $reply->isOk());
        if ($ok) {
            self::assertSame($expected, $reply->payload);
        } else {
            self::assertSame($expected, $reply->payload[CommandConstants::FIELD_MESSAGE]);
        }
    }

    private function drainCommandReply(): CommandReplyDTO
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                return $signal->data;
            }
        }
        self::fail('Command reply was not queued');
    }

    private function subscribe(string $acceptKey, string $code): void
    {
        $params = ['languageCode' => $code];
        Hilos::$sr->subscribeToPage(LanguageDetailPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, LanguageDetailPage::PAGE, $params));
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params): void {
            new LanguageDetailPage($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
        });
    }

    /** @return list<PageResponseSignalData> Card responses queued since the last call */
    private function drainCardResponses(): array
    {
        $responses = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $responses[] = $signal->data->data;
        }
        return $responses;
    }

    /** @return array<string, mixed> Card wire fields */
    private function cardOf(PageResponseSignalData $response): array
    {
        return $response->payload->toArray()[PagePayload::data][LanguageCardBrowserData::DATA];
    }

    private function assertCardsForBoth(bool $enabled, string $countrylessLocale): void
    {
        $responses = $this->drainCardResponses();
        self::assertCount(2, $responses);
        foreach ($responses as $response) {
            $card = $this->cardOf($response);
            self::assertSame($enabled, $card['enabled']);
            self::assertSame($countrylessLocale, $card['summary']['countrylessLocale']);
        }
    }

    private function clearFixtures(): void
    {
        Database::sqlRun("DELETE FROM hilos_country_name WHERE language_id IN "
            . "(SELECT id FROM hilos_language WHERE code IN ('de', 'en', 'es', 'fr'))");
        Database::sqlRun("DELETE FROM hilos_language_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('de', 'en', 'es', 'fr'))"
            . " OR in_language_id IN (SELECT id FROM hilos_language WHERE code IN ('de', 'en', 'es', 'fr'))");
        Database::sqlRun("DELETE FROM hilos_locale WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('de', 'en', 'es', 'fr'))");
        Database::sqlRun("DELETE FROM hilos_language WHERE code IN ('de', 'en', 'es', 'fr')");
        foreach ([Hilos::$db->countryNames, Hilos::$db->languageNames, Hilos::$db->locales, Hilos::$db->languages] as $collection) {
            $collection->getObjectCollection()?->reHydrate();
            $collection->clearCache();
        }
    }
}
