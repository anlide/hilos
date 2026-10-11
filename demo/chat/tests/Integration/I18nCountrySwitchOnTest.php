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
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountrySwitchOnActionDTO;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The country page and test command write through the i18n library's owned rows. */
final class I18nCountrySwitchOnTest extends IntegrationTestCase
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

    public function testSwitchOnEnablesCountrySetsSuccessMessageAndIsIdempotent(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries->actions->create('ca', '$', 'CAD');
        });
        self::assertFalse(Hilos::$db->countries['ca']->enabled);

        $page = new CountryDetailPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction(
                'country-ak',
                HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON,
                new HilosI18nCountrySwitchOnActionDTO('ca'),
            ));
        });
        self::assertTrue(Hilos::$db->countries['ca']->enabled);

        $page->sendActionSuccess('country-ak', HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON, 'switch-on-request');
        do {
            $success = Hilos::$sr->getNextQueuedSignal();
        } while ($success !== null && $success->signalName->getName() !== SignalConstants::ACTION_SUCCESS);
        self::assertSame(SignalConstants::ACTION_SUCCESS, $success?->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $success->data);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success->data->data);
        self::assertSame('Country switched on.', $success->data->data->message);
        self::assertNull($success->data->data->reply);

        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction(
                'country-ak',
                HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON,
                new HilosI18nCountrySwitchOnActionDTO('ca'),
            ));
        });
        self::assertTrue(Hilos::$db->countries['ca']->enabled);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries['ca']->actions->switchOff();
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $locale = Hilos::$db->locales->actions->create(
                $english,
                Hilos::$db->countries['ca'],
                'YYYY-MM-DD',
                'HH:mm:ss',
                '1,000.00',
                '+XX-XXXX-XXXX',
                'Street, House, City, Index',
                MeasurementSystem::METRIC,
                'und',
            );
            Hilos::$db->countries['ca']->actions->update('$', 'CAD', $locale->id);
        });
        self::assertFalse(Hilos::$db->countries['ca']->enabled);
        self::assertFalse(Hilos::$db->locales['en-CA']->enabled);
        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction(
                'country-ak',
                HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON,
                new HilosI18nCountrySwitchOnActionDTO('ca'),
            ));
        });
        self::assertTrue(Hilos::$db->countries['ca']->enabled);

        $this->expectException(TableActionException::class);
        $page->onAction(
            'country-ak',
            HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON,
            new HilosI18nCountrySwitchOnActionDTO('zz'),
        );
    }

    public function testBothOpenCardsReceiveTheEnabledState(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Switch-on country admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        foreach (['switch-card-one', 'switch-card-two'] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int)$admin->id, $session->token, (int)$session->id);
        }

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries->actions->create('ca', '$', 'CAD');
        });
        foreach (['switch-card-one', 'switch-card-two'] as $acceptKey) {
            $this->subscribe($acceptKey, 'ca');
            $cards = $this->drainCardResponses();
            self::assertCount(1, $cards);
            self::assertFalse($this->cardOf($cards[0])['enabled']);
        }

        $country = Hilos::$db->countries['ca'];
        $this->underAgent($this->agent, function (): void {
            new CountryDetailPage($this->agent)->onAction(
                'switch-card-one',
                HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON,
                new HilosI18nCountrySwitchOnActionDTO('ca'),
            );
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::countries,
            (string)$country->id,
            [EntityCountry::enabled => true],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $responses = $this->drainCardResponses();
        self::assertCount(2, $responses);
        foreach ($responses as $response) {
            self::assertTrue($this->cardOf($response)['enabled']);
        }
    }

    public function testTestCommandSwitchesOffBuiltInCountries(): void
    {
        $request = new CommandRequestDTO('country-off-1', CliCommands::I18N_TEST_COUNTRY_OFF, [
            I18nTestCommandConstants::FIELD_CODE => 'ca',
        ]);
        $this->underAgent($this->agent, function () use ($request): void {
            $this->agent->onSignalCommand($request, '', '');
        });
        $reply = $this->drainCommandReply();
        self::assertTrue($reply->isOk());
        self::assertSame(['code' => 'ca', 'enabled' => false], $reply->payload);
        self::assertNotNull(Hilos::$db->countries['ca']);
        self::assertFalse(Hilos::$db->countries['ca']->enabled);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries['ca']->actions->switchOn();
        });
        self::assertTrue(Hilos::$db->countries['ca']->enabled);

        $this->underAgent($this->agent, function () use ($request): void {
            $this->agent->onSignalCommand($request, '', '');
        });
        $replyAgain = $this->drainCommandReply();
        self::assertTrue($replyAgain->isOk());
        self::assertSame(['code' => 'ca', 'enabled' => false], $replyAgain->payload);
        self::assertFalse(Hilos::$db->countries['ca']->enabled);

        $this->underAgent($this->agent, function () use ($request): void {
            $this->agent->onSignalCommand($request, '', '');
        });
        self::assertTrue($this->drainCommandReply()->isOk());
        self::assertFalse(Hilos::$db->countries['ca']->enabled);

        $this->underAgent($this->agent, function (): void {
            $this->agent->onSignalCommand(new CommandRequestDTO('country-off-2', CliCommands::I18N_TEST_COUNTRY_OFF, [
                I18nTestCommandConstants::FIELD_CODE => 'zz',
            ]), '', '');
        });
        self::assertFalse($this->drainCommandReply()->isOk());
        self::assertNull(Hilos::$db->countries['zz']);

        $this->underAgent($this->agent, function (): void {
            $this->agent->onSignalCommand(new CommandRequestDTO('country-off-3', CliCommands::I18N_TEST_COUNTRY_OFF, [
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
