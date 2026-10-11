<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
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
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\I18nTestCommandConstants;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountrySwitchOffActionDTO;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The country page and test command write through the i18n library's owned rows. */
final class I18nCountrySwitchOffTest extends IntegrationTestCase
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
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        }
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testSwitchOffDisablesCountrySetsSuccessMessageAndIsIdempotent(): void
    {
        $this->underAgent($this->agent, static function (): void {
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $country = Hilos::$db->countries->actions->create('it', '€', 'EUR');
            $locale = Hilos::$db->locales->actions->create(
                $english,
                $country,
                'YYYY-MM-DD',
                'HH:mm:ss',
                '1,000.00',
                '+XX-XXXX-XXXX',
                'Street, House, City, Index',
                MeasurementSystem::METRIC,
                'und',
            );
            $country->actions->update('€', 'EUR', $locale->id);
            $country->actions->switchOn();
            Hilos::$db->countryNames->actions->createManual($country, $english, null, 'Italy');
        });
        self::assertTrue(Hilos::$db->countries['it']->enabled);

        $page = new CountryDetailPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction(
                'country-ak',
                HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF,
                new HilosI18nCountrySwitchOffActionDTO('it'),
            ));
        });
        self::assertFalse(Hilos::$db->countries['it']->enabled);
        self::assertNotNull(Hilos::$db->locales['en-IT']);
        self::assertNotNull(Hilos::$db->countryNames->findBase(
            Hilos::$db->countries['it']->id,
            Hilos::$db->languages['en']->id,
        ));

        $page->sendActionSuccess('country-ak', HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF, 'switch-off-request');
        do {
            $success = Hilos::$sr->getNextQueuedSignal();
        } while ($success !== null && $success->signalName->getName() !== SignalConstants::ACTION_SUCCESS);
        self::assertSame(SignalConstants::ACTION_SUCCESS, $success?->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $success->data);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success->data->data);
        self::assertSame('Country switched off.', $success->data->data->message);
        self::assertNull($success->data->data->reply);

        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction(
                'country-ak',
                HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF,
                new HilosI18nCountrySwitchOffActionDTO('it'),
            ));
        });
        self::assertFalse(Hilos::$db->countries['it']->enabled);

        $this->expectException(TableActionException::class);
        $page->onAction(
            'country-ak',
            HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF,
            new HilosI18nCountrySwitchOffActionDTO('zz'),
        );
    }

    public function testBothOpenCardsReceiveTheDisabledState(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Switch-off country admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        foreach (['switch-card-one', 'switch-card-two'] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int)$admin->id, $session->token, (int)$session->id);
        }

        $this->underAgent($this->agent, static function (): void {
            $country = Hilos::$db->countries->actions->create('it', '€', 'EUR');
            $country->actions->switchOn();
        });
        foreach (['switch-card-one', 'switch-card-two'] as $acceptKey) {
            $this->subscribe($acceptKey, 'it');
            $cards = $this->drainCardResponses();
            self::assertCount(1, $cards);
            self::assertTrue($this->cardOf($cards[0])['enabled']);
        }

        $country = Hilos::$db->countries['it'];
        $this->underAgent($this->agent, function (): void {
            new CountryDetailPage($this->agent)->onAction(
                'switch-card-one',
                HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF,
                new HilosI18nCountrySwitchOffActionDTO('it'),
            );
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::countries,
            (string)$country->id,
            [EntityCountry::enabled => false],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $responses = $this->drainCardResponses();
        self::assertCount(2, $responses);
        foreach ($responses as $response) {
            self::assertFalse($this->cardOf($response)['enabled']);
        }
    }

    public function testTestCommandSwitchesOnBuiltInCountries(): void
    {
        $request = new CommandRequestDTO('country-on-1', CliCommands::I18N_TEST_COUNTRY_ON, [
            I18nTestCommandConstants::FIELD_CODE => 'it',
        ]);
        $this->underAgent($this->agent, function () use ($request): void {
            $this->agent->onSignalCommand($request, '', '');
        });
        $reply = $this->drainCommandReply();
        self::assertTrue($reply->isOk());
        self::assertSame(['code' => 'it', 'enabled' => true], $reply->payload);
        $country = Hilos::$db->countries['it'];
        self::assertNotNull($country);
        self::assertSame('€', $country->currencySymbol);
        self::assertSame('EUR', $country->currencyCode);
        self::assertTrue($country->enabled);

        $this->underAgent($this->agent, function () use ($request): void {
            $this->agent->onSignalCommand($request, '', '');
        });
        $replyAgain = $this->drainCommandReply();
        self::assertTrue($replyAgain->isOk());
        self::assertSame(['code' => 'it', 'enabled' => true], $replyAgain->payload);
        self::assertTrue(Hilos::$db->countries['it']->enabled);

        $this->underAgent($this->agent, function (): void {
            $this->agent->onSignalCommand(new CommandRequestDTO('country-on-2', CliCommands::I18N_TEST_COUNTRY_ON, [
                I18nTestCommandConstants::FIELD_CODE => 'zz',
            ]), '', '');
        });
        self::assertFalse($this->drainCommandReply()->isOk());
        self::assertNull(Hilos::$db->countries['zz']);

        $this->underAgent($this->agent, function (): void {
            $this->agent->onSignalCommand(new CommandRequestDTO('country-on-3', CliCommands::I18N_TEST_COUNTRY_ON, [
                I18nTestCommandConstants::FIELD_CODE => 42,
            ]), '', '');
        });
        self::assertFalse($this->drainCommandReply()->isOk());
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
        return $response->payload->toArray()[PagePayload::data][CountryCardBrowserData::DATA];
    }

    private function subscribe(string $acceptKey, string $code): void
    {
        $params = ['countryCode' => $code];
        Hilos::$sr->subscribeToPage(
            CountryDetailPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, CountryDetailPage::PAGE, $params),
        );
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params): void {
            new CountryDetailPage($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
        });
    }

    private function clearFixtures(): void
    {
        Database::sqlRun('UPDATE hilos_country SET default_locale_id = NULL');
        Database::sqlRun('DELETE FROM hilos_country_name');
        Database::sqlRun('DELETE FROM hilos_language_name');
        Database::sqlRun('DELETE FROM hilos_locale');
        Database::sqlRun('DELETE FROM hilos_country');
        Database::sqlRun('DELETE FROM hilos_i18n_reflow');
        Database::sqlRun('DELETE FROM hilos_language');
        foreach ([
            Hilos::$db->countryNames,
            Hilos::$db->languageNames,
            Hilos::$db->locales,
            Hilos::$db->countries,
            Hilos::$db->languages,
        ] as $collection) {
            $collection->getObjectCollection()?->reHydrate();
            $collection->clearCache();
        }
    }
}
