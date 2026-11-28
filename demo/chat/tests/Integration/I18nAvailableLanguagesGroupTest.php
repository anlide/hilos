<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Group\DTO\GroupResponseSignalData;
use Hilos\Core\Group\GroupSubscriptionDispatcher;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\I18n\DTO\I18nTestLanguageCreateDTO;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\LocaleTemplates;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;

/** Exercises the group join and committed catalog changes through the serving library. */
final class I18nAvailableLanguagesGroupTest extends IntegrationTestCase
{
    private const string GROUP = 'hilos_languages';
    private const string GUEST = 'available-languages-guest';

    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new ChatSignalRouter();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
        foreach (array_keys(I18nLibraryAgent::OWNS_DB) as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->agent->getId());
        }
        $this->clearFixtures();
        $this->agent->onStop();
    }

    protected function tearDown(): void
    {
        try {
            $this->clearFixtures();
        } finally {
            $this->agent->onStop();
            TruthSourceRegistry::unregisterAgent($this->agent->getId());
            putenv($this->previousDefaultLanguage === false
                ? EnvConstants::HILOS_DEFAULT_LANGUAGE->name
                : EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
            Hilos::$sr = null;
            parent::tearDown();
        }
    }

    public function testGuestJoinAnswersWithTheFullAvailableList(): void
    {
        $this->underAgent($this->agent, function (): void {
            $russian = Hilos::$db->languages->actions->create('ru', 'Русский', false);
            $russian->actions->switchOn();
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $english->actions->switchOn();
            $german = Hilos::$db->languages->actions->create('de', 'Deutsch', false);
            $this->createLocale($german, null, true);

            $us = Hilos::$db->countries->actions->create('us', '$', 'USD');
            $us->actions->switchOn();
            $it = Hilos::$db->countries->actions->create('it', '€', 'EUR');
            $it->actions->switchOn();
            $ca = Hilos::$db->countries->actions->create('ca', '$', 'CAD');
            $ca->actions->switchOn();
            $gb = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
            $gb->actions->switchOn();
            $ua = Hilos::$db->countries->actions->create('ua', '₴', 'UAH');

            $this->createLocale($russian, $us, true);
            $this->createLocale($russian, $it, true);
            $this->createLocale($russian, $ca, true);
            $this->createLocale($russian, $gb, true);
            $this->createLocale($russian, $ua, true);
            $this->createLocale($russian, null, true);
            $this->createLocale($english, $us, false);

            $names = Hilos::$db->countryNames->actions;
            // Equal names with different casing, inserted in reverse locale-code order.
            $names->createManual($us, $english, null, 'Belgium');
            $names->createManual($it, $english, null, 'belgium');
            $names->createManual($it, $english, Hilos::$db->locales['en-US'], 'English override');
            $names->createManual($it, $russian, null, 'Италия');
            $names->createManual($gb, $english, null, '');
        });

        $this->underAgent($this->agent, function (): void {
            new GroupSubscriptionDispatcher($this->agent)->dispatchSubscribe(
                new WebSocketGroupSubscribeSignalDTO(self::GUEST, self::GROUP),
                self::GROUP,
            );
        });

        self::assertSame(self::GROUP, Hilos::$sr->groupSubscriptionName(self::GUEST, self::GROUP));
        $frames = $this->drainWebSocketFrames();
        self::assertSame([SignalTypeConstants::GROUP_RESPONSE], array_keys($frames));
        self::assertCount(1, $frames[SignalTypeConstants::GROUP_RESPONSE]);
        $response = $frames[SignalTypeConstants::GROUP_RESPONSE][0];
        self::assertSame(self::GUEST, $response->targetAcceptKey);
        self::assertInstanceOf(GroupResponseSignalData::class, $response->data);
        self::assertSame([
            GroupResponseSignalData::group => self::GROUP,
            GroupResponseSignalData::payload => ['languages' => [
                self::languageRow('en', 'English', []),
                self::languageRow('ru', 'Русский', [
                    self::localeRow('ru', null, null),
                    self::localeRow('ru-IT', 'it', 'belgium'),
                    self::localeRow('ru-US', 'us', 'Belgium'),
                    self::localeRow('ru-CA', 'ca', 'ca'),
                    self::localeRow('ru-GB', 'gb', 'gb'),
                ]),
            ]],
        ], $response->data->toArray());
    }

    public function testEachCatalogChangeReplacesTheListOnTheLibraryTick(): void
    {
        $this->underAgent($this->agent, function (): void {
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $english->actions->switchOn();
            Hilos::$db->languages->actions->create('de', 'Deutsch', false)->actions->switchOn();
            $country = Hilos::$db->countries->actions->create('it', '€', 'EUR');
            $country->actions->switchOn();
            $this->createLocale($english, $country, false);
            $this->createLocale($english, null, true);
            Hilos::$db->countryNames->actions->createManual($country, $english, null, 'Italy');
        });
        $this->tick();
        $this->drainWebSocketFrames();

        $this->underAgent($this->agent, static fn () => Hilos::$db->languages['de']->actions->switchOff());
        $this->assertNextUpdate([self::languageRow('en', 'English', [self::localeRow('en', null, null)])]);

        $this->underAgent($this->agent, static fn () => Hilos::$db->locales['en-IT']->actions->switchOn());
        $this->assertNextUpdate([self::languageRow('en', 'English', [
            self::localeRow('en', null, null),
            self::localeRow('en-IT', 'it', 'Italy'),
        ])]);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countryNames->findBase(Hilos::$db->countries['it']->id, Hilos::$db->languages['en']->id)
                ->actions->edit('Italian Republic');
        });
        $this->assertNextUpdate([self::languageRow('en', 'English', [
            self::localeRow('en', null, null),
            self::localeRow('en-IT', 'it', 'Italian Republic'),
        ])]);

        $this->underAgent($this->agent, static fn () => Hilos::$db->countries['it']->actions->switchOff());
        $this->assertNextUpdate([self::languageRow('en', 'English', [self::localeRow('en', null, null)])]);

        $this->underAgent($this->agent, static function (): void {
            $english = Hilos::$db->languages['en'];
            Hilos::$db->languageNames->actions->createManual($english, $english, null, 'English label')->actions->edit('Changed label');
        });
        $this->tick();
        self::assertSame([], $this->drainWebSocketFrames());
    }

    public function testOneCommandWithMultipleWritesQueuesOneUpdate(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries->actions->create('it', '€', 'EUR');
            Hilos::$db->countries->actions->create('de', '€', 'EUR');
        });
        $this->tick();
        $this->drainWebSocketFrames();

        $request = new CommandRequestDTO(
            'available-languages-create',
            CliCommands::I18N_TEST_LANGUAGE_CREATE,
            (new I18nTestLanguageCreateDTO('fr', 'Français', false, true))->toArray(),
        );
        $this->underAgent($this->agent, fn () => $this->agent->onSignalCommand($request, '', ''));
        do {
            $reply = Hilos::$sr->getNextQueuedSignal();
            self::assertNotInstanceOf(WebSocketSignalData::class, $reply?->data);
        } while ($reply !== null && !$reply->data instanceof CommandReplyDTO);

        self::assertInstanceOf(CommandReplyDTO::class, $reply?->data);
        self::assertTrue($reply->data->isOk());
        foreach (['it', 'de'] as $code) {
            self::assertNotNull(Hilos::$db->countryNames->findBase(Hilos::$db->countries[$code]->id, Hilos::$db->languages['fr']->id));
        }

        $this->assertNextUpdate([self::languageRow('fr', 'Français', [])]);
    }

    /** Separately committed switches share the next library tick and its replacement list. */
    public function testTwoCommittedWritesBeforeOneTickQueueOneUpdate(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false)->actions->switchOn();
            Hilos::$db->languages->actions->create('ru', 'Русский', false)->actions->switchOn();
            Hilos::$db->languages->actions->create('de', 'Deutsch', false)->actions->switchOn();
        });
        $this->tick();
        $this->drainWebSocketFrames();

        $this->underAgent($this->agent, static fn () => Hilos::$db->languages['ru']->actions->switchOff());
        $this->underAgent($this->agent, static fn () => Hilos::$db->languages['de']->actions->switchOff());

        $this->assertNextUpdate([self::languageRow('en', 'English', [])]);
    }

    public function testRolledBackMutationQueuesNoUpdate(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('de', 'Deutsch', false)->actions->switchOn();
        });
        $this->tick();
        $this->drainWebSocketFrames();

        $this->underAgent($this->agent, static function (): void {
            Database::transactionStart();
            try {
                Hilos::$db->languages['de']->actions->switchOff();
                self::assertFalse(Hilos::$db->languages['de']->enabled);
            } finally {
                Database::transactionRollback();
            }
        });
        self::assertTrue(Hilos::$db->languages['de']->enabled);
        $this->tick();
        self::assertSame([], $this->drainWebSocketFrames());
    }

    /** @param list<array<string, mixed>> $languages Expected complete replacement */
    private function assertNextUpdate(array $languages): void
    {
        self::assertSame([], $this->drainWebSocketFrames());
        $this->tick();
        $frames = $this->drainWebSocketFrames();
        self::assertSame([HilosSignalConstants::HILOS_LANGUAGES_STATE], array_keys($frames));
        $updates = $frames[HilosSignalConstants::HILOS_LANGUAGES_STATE];
        self::assertCount(1, $updates);
        self::assertSame(self::GROUP, $updates[0]->targetGroup);
        self::assertSame(['languages' => $languages], $updates[0]->data->toArray());
    }

    private function tick(): void
    {
        $this->underAgent($this->agent, $this->agent->onTick(...));
    }

    /** @return array<string, list<WebSocketSignalData>> Queued browser frames by signal name */
    private function drainWebSocketFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                if ($signal->signalName->getName() === HilosSignalConstants::HILOS_LANGUAGES_STATE) {
                    self::assertSame(SignalTypeConstants::WS_GROUP, $signal->signalType->getType());
                }
                $frames[$signal->signalName->getName()][] = $signal->data;
            }
        }

        return $frames;
    }

    private function createLocale(Language $language, ?Country $country, bool $enabled): void
    {
        $locale = Hilos::$db->locales->actions->create(
            $language,
            $country,
            LocaleTemplates::firsts(),
        );
        if ($enabled) {
            $locale->actions->switchOn();
        }
    }

    /**
     * @param list<array<string, mixed>> $locales Expected locales in wire order
     * @return array<string, mixed> Expected language row
     */
    private static function languageRow(string $code, string $nativeName, array $locales): array
    {
        return ['id' => Hilos::$db->languages[$code]->id, 'code' => $code, 'nativeName' => $nativeName, 'locales' => $locales];
    }

    /** @return array<string, mixed> Expected locale row */
    private static function localeRow(string $code, ?string $countryCode, ?string $countryName): array
    {
        return ['id' => Hilos::$db->locales[$code]->id, 'code' => $code, 'countryCode' => $countryCode, 'countryName' => $countryName];
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
