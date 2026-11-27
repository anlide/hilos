<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Generator;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\I18n\DTO\TabLanguage;
use Hilos\I18n\MeasurementSystem;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Ordinary\RtOrdinaryQueue;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosSessionConnections as StateSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateSessionConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\HilosSessionConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosSessionConnection;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Every handshake response tells its tab the language and the region, and the connection row keeps them (HIL-109).
 *
 * A guest and a signed-in person each hold one tab. The harness carries queued frames through the
 * ordinary declarative router from the real sessions library to a minimal project host, which sends
 * through the framework's stamped handshake, as every demo does. The reference is seeded through its
 * own actions; the choice of a person is not read yet, so the ladder takes what the tab's browser
 * asks for (HIL-2309), and the default language when it asks for nothing available.
 */
final class TabLanguageHandshakeIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const array TABLES = ['hilos_language', 'hilos_country', 'hilos_locale'];
    private const string REFERENCE_OWNER = 'test-agent:tab-language-reference';
    private const int PERSON = 7;
    private const string TOKEN_GUEST = '0123456789abcdef0123456789abcdef';
    private const string TOKEN_PERSON = 'fedcba9876543210fedcba9876543210';
    private const string TAB_GUEST = 'tab-language-guest';
    private const string TAB_PERSON = 'tab-language-person';
    private const string TAB_PERSON_SECOND = 'tab-language-person-second';
    private const string LOG_LINE = 'The tab language could not be decided';
    private const string WRITE_LOG_LINE = 'The tab language could not be written on its connection row';

    private TabLanguageHandshakeTestSessionsLibrary $sessions;
    private TabLanguageHandshakeTestHost $host;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRuntime = null;
    private string $previousAppClass;
    private string|false $previousDefaultLanguage;

    /** @return iterable<string, array{string, string, bool}> Default languages written either way */
    public static function defaultLanguages(): iterable
    {
        yield 'left to right' => ['es', 'Español', false];
        yield 'right to left' => ['ar', 'العربية', true];
    }

    /**
     * @param string $code Default language code
     * @param string $nativeName Its native name
     * @param bool $rtl Its writing direction
     * @throws HilosException When the reference, a handshake or a delivery hop fails
     */
    #[DataProvider('defaultLanguages')]
    public function testGuestAndSignedInTabsAreToldTheDefaultLanguageAndTheRowKeepsIt(string $code, string $nativeName, bool $rtl): void
    {
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $code);
        [$language, $locale] = $this->seedLanguage($code, $nativeName, $rtl);
        $expected = new TabLanguage($language->id, $code, $rtl, $locale->id, $locale->code, LocaleFormats::ofLocale($locale))->toArray();

        $this->handshake(self::TAB_GUEST, self::TOKEN_GUEST);
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON);
        $handshakes = $this->handshakeFrames($this->carryFrames());

        self::assertSame([self::TAB_GUEST, self::TAB_PERSON], array_map(
            static fn (WebSocketSignalData $frame): ?string => $frame->targetAcceptKey,
            $handshakes,
        ));
        $guest = $handshakes[0]->data->toArray()[HandshakeResponseSignalData::entities][HandshakeResponseSignalData::currentUser];
        $person = $handshakes[1]->data->toArray()[HandshakeResponseSignalData::entities][HandshakeResponseSignalData::currentUser];
        self::assertNull($guest);
        self::assertSame(self::PERSON, $person[HandshakeResponseSignalData::id]);
        foreach ($handshakes as $frame) {
            $tabLanguage = self::tabLanguageOf($frame);
            self::assertSame($expected, $tabLanguage);
            self::assertSame($rtl, $tabLanguage[TabLanguage::rtl]);
            self::assertSame([
                LocaleFormats::date, LocaleFormats::time, LocaleFormats::number, LocaleFormats::phone,
                LocaleFormats::address, LocaleFormats::measurement, LocaleFormats::collation,
            ], array_keys($tabLanguage[TabLanguage::formats]));
        }
        foreach ([self::TAB_GUEST, self::TAB_PERSON] as $acceptKey) {
            self::assertSame($language->id, Hilos::$rt->tabLanguageConnections[$acceptKey]->languageId);
            self::assertSame($locale->id, Hilos::$rt->tabLanguageConnections[$acceptKey]->localeId);
        }
    }

    /** @throws HilosException When the reference, a handshake or a delivery hop fails */
    public function testARepeatedFrameWithTheSameLanguageLeavesTheRowAlone(): void
    {
        [$language, $locale] = $this->seedLanguage('es', 'Español', false);

        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON);
        $first = $this->carryFrames();
        self::assertCount(1, $this->languageWrites($first), 'The first frame writes the language on the new row');

        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON);
        $repeat = $this->carryFrames();

        self::assertSame([], $this->languageWrites($repeat));
        $handshakes = $this->handshakeFrames($repeat);
        self::assertCount(1, $handshakes);
        self::assertSame($language->id, self::tabLanguageOf($handshakes[0])[TabLanguage::languageId]);
        self::assertSame($locale->id, Hilos::$rt->tabLanguageConnections[self::TAB_PERSON]->localeId);
    }

    /** @throws HilosException When the reference, a handshake or a delivery hop fails */
    public function testAProjectWithoutI18nIsToldNullAndTheRowStaysEmpty(): void
    {
        $this->seedLanguage('es', 'Español', false);
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, TabLanguageHandshakeWithoutI18nTestHilos::class);

        $this->handshake(self::TAB_GUEST, self::TOKEN_GUEST, 'es');
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, 'es-MX,es;q=0.9');
        $frames = $this->carryFrames();

        $this->assertToldNull($frames, [self::TAB_GUEST, self::TAB_PERSON]);
        self::assertSame([], $this->languageWrites($frames));
        foreach ([self::TAB_GUEST, self::TAB_PERSON] as $acceptKey) {
            self::assertNull(Hilos::$rt->tabLanguageConnections[$acceptKey]->browserLanguages);
        }
    }

    /**
     * The tab that connected is told the first available language its browser names by weight, and
     * its row keeps the request beside the language, in the one write that settles both.
     *
     * @throws HilosException When the reference, a handshake or a delivery hop fails
     */
    public function testATabIsToldTheLanguageItsBrowserAsksForAndTheRowKeepsTheRequest(): void
    {
        $this->seedLanguage('es', 'Español', false);
        [$russian, $russianLocale] = $this->seedLanguage('ru', 'Русский', false);

        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, 'ru-UA,ru;q=0.9,en;q=0.5');
        $frames = $this->carryFrames();

        $handshakes = $this->handshakeFrames($frames);
        self::assertCount(1, $handshakes);
        $tabLanguage = self::tabLanguageOf($handshakes[0]);
        self::assertSame($russian->id, $tabLanguage[TabLanguage::languageId]);
        self::assertSame($russianLocale->id, $tabLanguage[TabLanguage::localeId]);
        $row = Hilos::$rt->tabLanguageConnections[self::TAB_PERSON];
        self::assertSame('ru-UA,ru,en', $row->browserLanguages);
        self::assertSame($russian->id, $row->languageId);
        self::assertSame($russianLocale->id, $row->localeId);
        $writes = $this->languageWrites($frames);
        self::assertCount(1, $writes);
        self::assertSame('ru-UA,ru,en', $writes[0]->row[StateSessionConnection::browserLanguages]);
    }

    /**
     * The handshake of a second tab reaches every tab of the session, and a restatement names them
     * all without any header: each tab keeps the language of its own browser and its own row, and
     * only the tab that connected is written.
     *
     * @throws HilosException When the reference, a handshake or a delivery hop fails
     */
    public function testEachTabOfASessionKeepsItsOwnBrowserLanguageOnFramesThatReachThemAll(): void
    {
        $this->seedLanguage('es', 'Español', false);
        [$russian] = $this->seedLanguage('ru', 'Русский', false);
        [$german] = $this->seedLanguage('de', 'Deutsch', false);
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, 'ru-UA,ru;q=0.9,en;q=0.5');
        $this->carryFrames();
        $expected = [
            self::TAB_PERSON => [$russian->id, 'ru-UA,ru,en'],
            self::TAB_PERSON_SECOND => [$german->id, 'de'],
        ];

        $this->handshake(self::TAB_PERSON_SECOND, self::TOKEN_PERSON, 'de');
        $second = $this->carryFrames();

        $this->assertTabsKeepTheirBrowserLanguage($second, $expected);
        $writes = $this->languageWrites($second);
        self::assertCount(1, $writes, 'Only the tab that connected is written');
        self::assertSame(self::TAB_PERSON_SECOND, $writes[0]->stateId);

        $restated = $this->restate(self::TOKEN_PERSON, [self::TAB_PERSON, self::TAB_PERSON_SECOND]);

        $this->assertTabsKeepTheirBrowserLanguage($restated, $expected);
        self::assertSame([], $this->languageWrites($restated));
    }

    /** @throws HilosException When a handshake or a delivery hop fails */
    public function testATabBeforeTheDefaultLanguageHasARowIsToldNull(): void
    {
        $this->handshake(self::TAB_GUEST, self::TOKEN_GUEST);

        $this->assertToldNull($this->carryFrames(), [self::TAB_GUEST]);
    }

    /** @throws HilosException When the reference, a handshake or a delivery hop fails */
    public function testALadderFailureIsLoggedAndTheResponseStillGoesOut(): void
    {
        $this->seedLanguage('es', 'Español', false);
        $this->handshake(self::TAB_GUEST, self::TOKEN_GUEST);
        $this->carryFrames();
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=ez');

        [$frames, $journal] = $this->journalOf(function (): array {
            $this->handshake(self::TAB_GUEST, self::TOKEN_GUEST);

            return $this->carryFrames();
        });

        $this->assertToldNull($frames, [self::TAB_GUEST]);
        self::assertStringContainsString(self::LOG_LINE, $journal);
        self::assertStringContainsString(EnvConstants::HILOS_DEFAULT_LANGUAGE->name, $journal);
    }

    /**
     * The frame's own connection write takes the last room of the host's RT FIFO, as when the
     * stream sender has none, so the language write of each tab is refused. Every tab of the
     * frame is still answered, each is told null rather than a language its row does not hold,
     * and each row keeps what it held - the second tab its earlier language.
     *
     * @throws HilosException When the reference, a handshake or a delivery hop fails
     */
    public function testARefusedLanguageWriteIsLoggedAndEveryTabOfTheFrameIsStillAnswered(): void
    {
        [$language, $locale] = $this->seedLanguage('es', 'Español', false);
        $this->seedLanguage('ar', 'العربية', true);
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON);
        $this->carryFrames();
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=ar');
        ExecutionContext::run(new ExecutionFrame(agentId: $this->host->getId()), static function (): void {
            RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1));
        });
        RtOrdinaryAdmission::consumeBy(static fn (): bool => false);

        [$frames, $journal] = $this->journalOf(function (): array {
            $this->handshake(self::TAB_PERSON_SECOND, self::TOKEN_PERSON);

            return $this->carryFrames();
        });

        $this->assertToldNull($frames, [self::TAB_PERSON_SECOND, self::TAB_PERSON], [
            self::TAB_PERSON_SECOND => [null, null],
            self::TAB_PERSON => [$language->id, $locale->id],
        ]);
        self::assertSame([], $this->languageWrites($frames));
        self::assertSame(2, substr_count($journal, self::WRITE_LOG_LINE));
        self::assertStringNotContainsString(self::LOG_LINE, $journal, 'The ladder decided; only the write was refused');
    }

    /** @throws HilosException When the schema or fixture setup fails */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);
        Schema::reset();
        Schema::initialize();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=es');
        $this->previousAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, TabLanguageHandshakeTestHilos::class);

        Database::sqlRun('INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, ?)', [self::PERSON, 'Tab language person']);
        Hilos::$db->sessions->actions->createAnonymous(self::TOKEN_GUEST);
        Hilos::$db->sessions->actions->createAnonymous(self::TOKEN_PERSON)->actions->bindUser(self::PERSON);
        foreach ([HilosDbContext::languages, HilosDbContext::countries, HilosDbContext::locales] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), self::REFERENCE_OWNER);
        }
        $this->previousRouter = Hilos::$sr;
        $this->previousRuntime = Hilos::$rt;
        Hilos::$sr = new TabLanguageHandshakeTestRouter();
        Hilos::$rt = new TabLanguageHandshakeTestRuntime();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->sessions = new TabLanguageHandshakeTestSessionsLibrary();
        $this->host = new TabLanguageHandshakeTestHost();
        foreach ([$this->sessions, $this->host] as $agent) {
            OwnershipDeclaration::claimAll($agent);
        }
    }

    /** @throws HilosException When the parent schema teardown fails */
    protected function tearDown(): void
    {
        foreach ([$this->sessions, $this->host] as $agent) {
            TruthSourceRegistry::unregisterAgent($agent->getId());
            RtTruthSourceRegistry::unregisterAgent($agent->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
        }
        TruthSourceRegistry::unregisterAgent(self::REFERENCE_OWNER);
        SourceChangeBus::reset();
        RtOrdinaryAdmission::reset();
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRuntime;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        }
        self::runStubs(down: true);
        Schema::reset();

        parent::tearDown();
    }

    /**
     * @param WebSocketSignalData $frame Delivered handshake reply
     * @return ?array<string, mixed> Its tab language node, as the wire carries it
     */
    private static function tabLanguageOf(WebSocketSignalData $frame): ?array
    {
        $section = $frame->data->toArray()[HandshakeResponseSignalData::data];
        self::assertArrayHasKey(HandshakeResponseSignalData::tabLanguage, $section, 'The key is written on every response');

        return $section[HandshakeResponseSignalData::tabLanguage];
    }

    /**
     * @param list<SignalDTO> $frames Observed queue
     * @param list<string> $acceptKeys Tabs that must each be told null, in delivery order
     * @param array<string, array{?int, ?int}> $rows Language and locale ids each row keeps; an empty row when not named
     */
    private function assertToldNull(array $frames, array $acceptKeys, array $rows = []): void
    {
        $handshakes = $this->handshakeFrames($frames);
        self::assertSame($acceptKeys, array_map(
            static fn (WebSocketSignalData $frame): ?string => $frame->targetAcceptKey,
            $handshakes,
        ));
        foreach ($handshakes as $frame) {
            self::assertNull(self::tabLanguageOf($frame));
        }
        foreach ($acceptKeys as $acceptKey) {
            [$languageId, $localeId] = $rows[$acceptKey] ?? [null, null];
            self::assertSame($languageId, Hilos::$rt->tabLanguageConnections[$acceptKey]->languageId);
            self::assertSame($localeId, Hilos::$rt->tabLanguageConnections[$acceptKey]->localeId);
        }
    }

    /**
     * @param list<SignalDTO> $frames Observed queue
     * @param array<string, array{int, string}> $tabs Language id each tab is told and the request its row keeps
     */
    private function assertTabsKeepTheirBrowserLanguage(array $frames, array $tabs): void
    {
        $told = [];
        foreach ($this->handshakeFrames($frames) as $frame) {
            $told[(string)$frame->targetAcceptKey] = self::tabLanguageOf($frame)[TabLanguage::languageId];
        }
        ksort($told);
        $expected = array_map(static fn (array $tab): int => $tab[0], $tabs);
        ksort($expected);
        self::assertSame($expected, $told);
        foreach ($tabs as $acceptKey => [$languageId, $browserLanguages]) {
            self::assertSame($languageId, Hilos::$rt->tabLanguageConnections[$acceptKey]->languageId);
            self::assertSame($browserLanguages, Hilos::$rt->tabLanguageConnections[$acceptKey]->browserLanguages);
        }
    }

    /**
     * Hands the host a restatement of the session, as the library sends one: every named tab, no header.
     *
     * @param string $token Session cookie token
     * @param list<string> $acceptKeys Tabs the frame names
     * @return list<SignalDTO> All frames observed while it is carried
     * @throws HilosException When the session cannot be read or a delivery hop fails
     */
    private function restate(string $token, array $acceptKeys): array
    {
        $session = Hilos::$db->sessions->findByToken($token);
        self::assertNotNull($session);
        $frame = new SessionStateSignalData(
            sessionToken: $token,
            sessionId: $session->id,
            userId: $session->userId,
            acceptKeys: $acceptKeys,
        );
        ExecutionContext::run(new ExecutionFrame(agentId: $this->host->getId()), fn () => $this->host->onSignalAgent(
            new AgentSignalData($frame), 'test', HilosSignalConstants::HILOS_SESSION_STATE,
        ));

        return $this->carryFrames();
    }

    /**
     * @template T
     * @param Closure(): T $during Work whose journal is wanted
     * @return array{T, string} What the work returned, and every line it logged in any file
     */
    private function journalOf(Closure $during): array
    {
        $logDirectory = sys_get_temp_dir() . '/hilos-tab-language-log-' . bin2hex(random_bytes(8));
        mkdir($logDirectory);
        Logger::setLogFile($logDirectory . '/daemon.log');

        try {
            $result = $during();
            $journal = '';
            foreach (array_diff((array)scandir($logDirectory), ['.', '..']) as $entry) {
                $journal .= (string)file_get_contents($logDirectory . '/' . $entry);
            }
        } finally {
            Logger::resetLogFile();
            foreach (array_diff((array)scandir($logDirectory), ['.', '..']) as $entry) {
                unlink($logDirectory . '/' . $entry);
            }
            rmdir($logDirectory);
        }

        return [$result, $journal];
    }

    /**
     * @param string $code Language code
     * @param string $nativeName Native name
     * @param bool $rtl Writing direction
     * @return array{Language, Locale} Switched-on language and its switched-on countryless locale
     * @throws HilosException When an action refuses
     */
    private function seedLanguage(string $code, string $nativeName, bool $rtl): array
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: self::REFERENCE_OWNER), static function () use ($code, $nativeName, $rtl): array {
            $language = Hilos::$db->languages->actions->create($code, $nativeName, $rtl);
            $language->actions->switchOn();
            $locale = Hilos::$db->locales->actions->create(
                $language, null, 'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
                'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
            );
            $locale->actions->switchOn();

            return [$language, $locale];
        });
    }

    /**
     * @param string $acceptKey Tab arriving or reconnecting
     * @param string $token Browser cookie token
     * @param ?string $acceptLanguage What the browser sent in Accept-Language, or null when it sent no header
     */
    private function handshake(string $acceptKey, string $token, ?string $acceptLanguage = null): void
    {
        $headers = $acceptLanguage === null ? [] : [strtolower(HttpConstants::HEADER_ACCEPT_LANGUAGE) => $acceptLanguage];
        $handshake = WebSocketHandshakeSignalDTO::fromArray(new WebSocketHandshakeSignalDTO(
            headers: $headers,
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: null,
            sessionToken: $token,
        )->toArray());
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->sessions->getId()),
            fn () => $this->sessions->onSignalHandshake($handshake, 'test', 'handshake'),
        );
    }

    /** @return list<SignalDTO> All frames observed, including the real intermediate hops */
    private function carryFrames(): array
    {
        $frames = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signal = SignalDTO::fromArray($queued->toArray());
            $frames[] = $signal;
            if ($signal->signalType->getType() !== SignalTypeConstants::AGENT_SIGNAL) {
                continue;
            }
            $destinations = Hilos::$sr->getDestinations($signal);
            self::assertCount(1, $destinations);
            self::assertInstanceOf(AgentDestination::class, $destinations[0]);
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            $agent = match ($destinations[0]->agentType) {
                TabLanguageHandshakeTestSessionsLibrary::AGENT_TYPE => $this->sessions,
                TabLanguageHandshakeTestHost::AGENT_TYPE => $this->host,
                default => self::fail('Unexpected destination: ' . $destinations[0]->agentType),
            };
            ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static fn () => $agent->onSignalAgent(
                $signal->data, SignalSource::describe($signal->signalSource), $signal->signalName->getName(),
            ));
        }

        return $frames;
    }

    /**
     * @param list<SignalDTO> $frames Observed queue
     * @return list<WebSocketSignalData> Actual stamped handshake replies
     */
    private function handshakeFrames(array $frames): array
    {
        $handshakes = [];
        foreach ($frames as $signal) {
            if ($signal->signalName->getName() !== TabLanguageHandshakeTestHost::HANDSHAKE_RESPONSE) {
                continue;
            }
            self::assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(HandshakeResponseSignalData::class, $signal->data->data);
            $handshakes[] = $signal->data;
        }

        return $handshakes;
    }

    /**
     * @param list<SignalDTO> $frames Observed queue
     * @return list<RtSyncUpdatedSignalData> Updates of a connection row that carry its language, region or browser request
     */
    private function languageWrites(array $frames): array
    {
        $writes = [];
        foreach ($frames as $signal) {
            if ($signal->signalType->getType() !== SignalConstants::RT_SYNC_UPDATED) {
                continue;
            }
            self::assertInstanceOf(RtSyncUpdatedSignalData::class, $signal->data);
            if ($signal->data->collectionKey !== TabLanguageHandshakeTestRuntime::tabLanguageConnections) {
                continue;
            }
            if (array_key_exists(StateSessionConnection::languageId, $signal->data->row)
                || array_key_exists(StateSessionConnection::localeId, $signal->data->row)
                || array_key_exists(StateSessionConnection::browserLanguages, $signal->data->row)
            ) {
                $writes[] = $signal->data;
            }
        }

        return $writes;
    }

    /**
     * @param bool $down Whether to drop the tables instead of creating them
     * @throws DatabaseException When a stub fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the up stub carries no suffix.
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/** The sessions library's production handshake behavior, without overrides. */
final class TabLanguageHandshakeTestSessionsLibrary extends AbstractSessionsLibraryAgent
{
    public const string AGENT_TYPE = 'tab_language_test_sessions';
}

/** Minimal project session receiver, using the framework's identity builder and stamped send. */
final class TabLanguageHandshakeTestHost extends AbstractAgent
{
    public const string AGENT_TYPE = 'tab_language_test_host';
    public const string HANDSHAKE_RESPONSE = 'handshake_response';
    public const array AGENT_SIGNALS = [HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class];
    public const array OWNS_RT = [TabLanguageHandshakeTestRuntime::tabLanguageConnections => TruthSourceOperation::ALL];

    /** No lifecycle cleanup is needed in this in-process fixture. */
    public function onStop(): void
    {
    }

    /**
     * @param AgentSignalData $data Serialized session-state wrapper
     * @param string $sender Sender identity, unused by the fixture
     * @param string $name Session-state signal name
     * @throws HilosException When identity construction or runtime settlement fails
     * @throws LogicException When a collection's class constants are not configured
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_SESSION_STATE:
                if (!$data->data instanceof SessionStateSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SessionStateSignalData::class, $data->data);
                }
                $state = $data->data;
                $identity = $this->handshakeIdentity($state);
                $connections = Hilos::$rt->tabLanguageConnections;
                foreach ($connections->acceptKeysForSessionFrame($state->acceptKeys, $state->sessionToken) as $acceptKey) {
                    if (!isset($connections[$acceptKey])) {
                        $connections->actions->register($acceptKey, $state->userId, $state->sessionToken, $state->sessionId);
                    } else {
                        $connections[$acceptKey]->actions->bindUser($state->userId);
                    }
                    $this->sendHandshakeResponse(self::HANDSHAKE_RESPONSE, $acceptKey, $identity, $state);
                }
                return;
            default:
                throw new AgentUnknownSignalException($name);
        }
    }
}

/** Fixture project with the I18N feature, holding the two agents of the harness. */
abstract class TabLanguageHandshakeTestHilos extends Hilos
{
    public const array AGENTS = [
        TabLanguageHandshakeTestSessionsLibrary::AGENT_TYPE => [AgentRegistryKey::WORKER => TabLanguageHandshakeTestSessionsLibrary::class],
        TabLanguageHandshakeTestHost::AGENT_TYPE => [AgentRegistryKey::WORKER => TabLanguageHandshakeTestHost::class],
    ];

    protected const array FEATURES = [HilosFeature::I18N];
}

/** The same fixture project without the I18N feature. */
abstract class TabLanguageHandshakeWithoutI18nTestHilos extends TabLanguageHandshakeTestHilos
{
    protected const array FEATURES = [];
}

/** Uses ordinary topology resolution against the fixture project registry. */
final class TabLanguageHandshakeTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Fixture topology owner */
    protected function hilosClass(): string
    {
        return TabLanguageHandshakeTestHilos::class;
    }
}

/** @property-read TabLanguageHandshakeTestConnections $tabLanguageConnections Fixture sockets */
final class TabLanguageHandshakeTestRuntime extends RtContext
{
    public const string tabLanguageConnections = 'tabLanguageConnections';

    /**
     * Mounts a fully represented session-stage collection; every mutation uses actions.
     *
     * @throws HilosException When the fixture collection cannot be represented
     */
    public function configure(): void
    {
        $this->_stateCollections[self::tabLanguageConnections] = TabLanguageHandshakeTestConnectionStates::init();
        $this->setRepresent(self::tabLanguageConnections, TabLanguageHandshakeTestConnections::class,
            TabLanguageHandshakeTestConnectionsActions::class, TabLanguageHandshakeTestConnectionActions::class);
    }
}

/** Session-stage rows, with no extra fields. */
final class TabLanguageHandshakeTestConnectionStates extends StateSessionConnections
{
    public const string STATE_CLASS = TabLanguageHandshakeTestConnectionState::class;
}

/** Session-stage row with an empty project half. */
final class TabLanguageHandshakeTestConnectionState extends StateSessionConnection
{
    /** The fixture adds no project defaults. */
    protected function initOwn(): Generator
    {
        yield new RtWork();
    }

    /** @param RtRowReader $row Incoming row, with no project fields */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield new RtWork();
    }

    /** @param RtRowWriter $out Destination, given no project fields */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield new RtWork();
    }

    /** @param RtRowReader $diff Incoming diff, with no project fields */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield new RtWork();
    }
}

/**
 * Fully represented fixture sockets, inheriting the production session lookups.
 *
 * @extends HilosSessionConnections<TabLanguageHandshakeTestConnection, TabLanguageHandshakeTestConnectionsActions>
 */
final class TabLanguageHandshakeTestConnections extends HilosSessionConnections
{
    /**
     * @param RtState $state Session-stage state row
     * @return TabLanguageHandshakeTestConnection Read-only fixture connection
     */
    protected function createRtItem(RtState $state): TabLanguageHandshakeTestConnection
    {
        return new TabLanguageHandshakeTestConnection($state);
    }
}

/** @extends HilosSessionConnection<TabLanguageHandshakeTestConnectionState> */
final class TabLanguageHandshakeTestConnection extends HilosSessionConnection
{
}

/**
 * Session registration and the tab language use the inherited guarded collection actions.
 *
 * @property-read TabLanguageHandshakeTestConnectionStates $stateCollection Backing fixture sockets
 */
final class TabLanguageHandshakeTestConnectionsActions extends HilosSessionConnectionsActions
{
}

/** Connection changes and removal use the inherited guarded item actions. */
final class TabLanguageHandshakeTestConnectionActions extends HilosConnectionActions
{
}
