<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
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
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Exception\DefaultLanguageProtectedException;
use Hilos\I18n\I18nLanguageOnCommandConstants;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageSwitchOffActionDTO;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The language page and test command write through the i18n library's owned rows. */
final class I18nLanguageSwitchOffTest extends IntegrationTestCase
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

    public function testSwitchOffKeepsDependentRowsAndIsIdempotent(): void
    {
        $this->underAgent($this->agent, static function (): void {
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $french = Hilos::$db->languages->actions->create('fr', 'Français', false);
            $french->actions->switchOn();
            Hilos::$db->locales->actions->create(
                $french, null, 'Y-m-d', 'H:i', '1,234.56', '+1 555', 'street, city', MeasurementSystem::METRIC, 'und',
            );
            Hilos::$db->languageNames->actions->createManual($french, $english, null, 'French');
        });

        $page = new LanguageDetailPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction(
                'language-ak',
                HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF,
                new HilosI18nLanguageSwitchOffActionDTO('fr'),
            ));
        });
        self::assertFalse(Hilos::$db->languages['fr']->enabled);
        self::assertNotNull(Hilos::$db->locales['fr']);
        self::assertNotNull(Hilos::$db->languageNames->findBase(
            Hilos::$db->languages['fr']->id,
            Hilos::$db->languages['en']->id,
        ));

        $this->underAgent($this->agent, static function () use ($page): void {
            $page->onAction('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF,
                new HilosI18nLanguageSwitchOffActionDTO('fr'));
        });
        self::assertFalse(Hilos::$db->languages['fr']->enabled);
    }

    public function testDefaultAndUnknownLanguageAreRefused(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false)->actions->switchOn();
        });
        $page = new LanguageDetailPage($this->agent);
        try {
            $this->underAgent($this->agent, static function () use ($page): void {
                $page->onAction('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF,
                    new HilosI18nLanguageSwitchOffActionDTO('en'));
            });
            self::fail('The default language must be protected');
        } catch (DefaultLanguageProtectedException) {
            self::assertTrue(Hilos::$db->languages['en']->enabled);
        }

        $this->expectException(TableActionException::class);
        $page->onAction('language-ak', HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF,
            new HilosI18nLanguageSwitchOffActionDTO('zz'));
    }

    public function testBothOpenCardsReceiveTheDisabledState(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Switch-off card admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        foreach (['switch-card-one', 'switch-card-two'] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int)$admin->id, $session->token, (int)$session->id);
        }

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('fr', 'Français', false)->actions->switchOn();
        });
        foreach (['switch-card-one', 'switch-card-two'] as $acceptKey) {
            $this->subscribe($acceptKey, 'fr');
            $cards = $this->drainCardResponses();
            self::assertCount(1, $cards);
            self::assertTrue($this->cardOf($cards[0])['enabled']);
        }

        $language = Hilos::$db->languages['fr'];
        $this->underAgent($this->agent, function (): void {
            new LanguageDetailPage($this->agent)->onAction(
                'switch-card-one',
                HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF,
                new HilosI18nLanguageSwitchOffActionDTO('fr'),
            );
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::languages,
            (string)$language->id,
            [EntityLanguage::enabled => false],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $responses = $this->drainCardResponses();
        self::assertCount(2, $responses);
        foreach ($responses as $response) {
            self::assertFalse($this->cardOf($response)['enabled']);
        }
    }

    public function testTestCommandCreatesAndEnablesOnlyKnownLanguages(): void
    {
        $request = new CommandRequestDTO('language-on-1', CliCommands::I18N_TEST_LANGUAGE_ON, [
            I18nLanguageOnCommandConstants::FIELD_CODE => 'fr',
        ]);
        $this->underAgent($this->agent, function () use ($request): void {
            $this->agent->onSignalCommand($request, '', '');
        });
        $reply = $this->drainCommandReply();
        self::assertTrue($reply->isOk());
        self::assertSame(['code' => 'fr', 'enabled' => true], $reply->payload);
        self::assertSame('Français', Hilos::$db->languages['fr']->nativeName);
        self::assertFalse(Hilos::$db->languages['fr']->rtl);
        self::assertTrue(Hilos::$db->languages['fr']->enabled);

        $this->underAgent($this->agent, function () use ($request): void {
            $this->agent->onSignalCommand($request, '', '');
        });
        self::assertTrue($this->drainCommandReply()->isOk());

        $this->underAgent($this->agent, function (): void {
            $this->agent->onSignalCommand(new CommandRequestDTO('language-on-2', CliCommands::I18N_TEST_LANGUAGE_ON, [
                I18nLanguageOnCommandConstants::FIELD_CODE => 'zz',
            ]), '', '');
        });
        self::assertFalse($this->drainCommandReply()->isOk());
        self::assertNull(Hilos::$db->languages['zz']);
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
        return $response->payload->toArray()[PagePayload::data][LanguageCardBrowserData::DATA];
    }

    private function subscribe(string $acceptKey, string $code): void
    {
        $params = ['languageCode' => $code];
        Hilos::$sr->subscribeToPage(
            LanguageDetailPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, LanguageDetailPage::PAGE, $params),
        );
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params): void {
            new LanguageDetailPage($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
        });
    }

    private function clearFixtures(): void
    {
        Database::sqlRun("DELETE FROM hilos_country_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'fr'))");
        Database::sqlRun("DELETE FROM hilos_language_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'fr'))"
            . " OR in_language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'fr'))");
        Database::sqlRun("DELETE FROM hilos_locale WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'fr'))");
        Database::sqlRun("DELETE FROM hilos_language WHERE code IN ('en', 'fr')");
        foreach ([Hilos::$db->countryNames, Hilos::$db->languageNames, Hilos::$db->locales, Hilos::$db->languages] as $collection) {
            $collection->getObjectCollection()?->reHydrate();
            $collection->clearCache();
        }
    }
}
