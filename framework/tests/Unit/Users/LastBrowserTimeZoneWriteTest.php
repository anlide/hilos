<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use ArrayAccess;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\Agent\AbstractUserAgentDaemon;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The zone a browser named reaches the person at the keyboard's agent, which remembers it and answers nobody (HIL-1582).
 *
 * Every report goes through the topology the user agent declares: the payload arrives untyped, as it does
 * across processes, and the route hydrates it and names the agent by the person id.
 */
final class LastBrowserTimeZoneWriteTest extends TestCase
{
    private ?HilosDbContext $previousDb = null;

    private LastBrowserTimeZoneTestDb $db;

    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
        $this->db = new LastBrowserTimeZoneTestDb();
        Hilos::$db = $this->db;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;

        parent::tearDown();
    }

    /** Both fields travel as they are, and a restored report says the same. */
    public function testTheReportRoundtripsItsFieldsLiterally(): void
    {
        $report = new UserLastBrowserTimeZoneWriteSignalData(42, 'Europe/Kiev');

        self::assertSame(['userId' => 42, 'lastBrowserTimeZone' => 'Europe/Kiev'], $report->toArray());
        $restored = UserLastBrowserTimeZoneWriteSignalData::fromArray($report->toArray());
        self::assertSame(42, $restored->userId);
        self::assertSame('Europe/Kiev', $restored->lastBrowserTimeZone);
    }

    /**
     * @param array<string, mixed> $payload Report that lacks a field or carries one of another type
     */
    #[DataProvider('incompletePayloads')]
    public function testAReportWithoutBothFieldsIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);

        UserLastBrowserTimeZoneWriteSignalData::fromArray($payload);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function incompletePayloads(): array
    {
        return [
            'no person' => [['lastBrowserTimeZone' => 'Europe/Warsaw']],
            'no zone' => [['userId' => 42]],
            'person as text' => [['userId' => '42', 'lastBrowserTimeZone' => 'Europe/Warsaw']],
            'zone as null' => [['userId' => 42, 'lastBrowserTimeZone' => null]],
        ];
    }

    /** @param int $userId Person id that names nobody */
    #[DataProvider('nonPositiveIds')]
    public function testAReportForNoPersonIsRefused(int $userId): void
    {
        $this->expectException(InvalidFormatException::class);

        UserLastBrowserTimeZoneWriteSignalData::fromArray(['userId' => $userId, 'lastBrowserTimeZone' => 'Europe/Warsaw']);
    }

    /** @return array<string, array{int}> */
    public static function nonPositiveIds(): array
    {
        return ['zero' => [0], 'negative' => [-3]];
    }

    /** The route addresses the person's own agent and hydrates the declared report. */
    public function testTheRouteNamesThePersonsAgentAndHydratesTheReport(): void
    {
        $router = new LastBrowserTimeZoneTestRouter();
        $signal = $this->untyped(42, 'Europe/Warsaw');

        self::assertEquals([new AgentDestination(HilosAgentType::HILOS_USER, '42')], $router->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE),
            $signal,
        )));
        $hydrated = $router->createAgentSignalPayloadDTO(HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE, $signal);
        self::assertInstanceOf(UserLastBrowserTimeZoneWriteSignalData::class, $hydrated->data);
        self::assertSame(['userId' => 42, 'lastBrowserTimeZone' => 'Europe/Warsaw'], $hydrated->data->toArray());
    }

    /** A zone the memory does not hold yet is written, and nothing answers: nobody waits on it. */
    public function testANewZoneIsWrittenWithoutAnAnswer(): void
    {
        $this->db->stored = 'Europe/Berlin';
        $agent = new LastBrowserTimeZoneTestAgent('42');

        $this->report($agent, 42, 'Europe/Warsaw');

        self::assertSame(['Europe/Warsaw'], $agent->written);
        self::assertSame([], $agent->errors);
        self::assertSame([], $agent->sent);
    }

    /** A zone the memory already holds - every reconnect repeats it - writes nothing. */
    public function testARepeatedZoneWritesNothing(): void
    {
        $this->db->stored = 'Europe/Warsaw';
        $agent = new LastBrowserTimeZoneTestAgent('42');

        $this->report($agent, 42, 'Europe/Warsaw');

        self::assertSame([], $agent->written);
        self::assertSame([], $agent->errors);
        self::assertSame([], $agent->sent);
    }

    /** A report naming another person never reached the right agent, and is refused before anything else. */
    public function testAnotherPersonCannotRetargetTheOwner(): void
    {
        $agent = new LastBrowserTimeZoneTestAgent('41');
        try {
            $this->report($agent, 42, 'Europe/Warsaw');
            self::fail('The owner must reject a report naming another person');
        } catch (AgentException $refusal) {
            self::assertStringContainsString('user #42 reached the agent of user #41', $refusal->getMessage());
            self::assertSame([], $agent->written);
            self::assertSame([], $agent->errors);
            self::assertSame([], $agent->sent);
        }
    }

    /** An erased person is not written and not brought back; the agent's log says why. */
    public function testAMissingPersonIsLoggedAndNotWritten(): void
    {
        $this->db->present = false;
        $agent = new LastBrowserTimeZoneTestAgent('42');

        $this->report($agent, 42, 'Europe/Warsaw');

        self::assertSame([], $agent->written);
        self::assertSame(['Last browser time zone for #42 not written: No such user: 42'], $agent->errors);
        self::assertSame([], $agent->sent);
    }

    /** A person folded into someone else is not written, and the zone is not carried over to the survivor. */
    public function testAMergedPersonIsLoggedAndNotWritten(): void
    {
        $this->db->merged = true;
        $agent = new LastBrowserTimeZoneTestAgent('42');

        $this->report($agent, 42, 'Europe/Warsaw');

        self::assertSame([], $agent->written);
        self::assertSame(
            ['Last browser time zone for #42 not written: ' . AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE],
            $agent->errors,
        );
        self::assertSame([], $agent->sent);
    }

    /** A process that may not read the person says so in its own words, apart from a refused write. */
    public function testAWiringRefusalIsLoggedApartAndNotWritten(): void
    {
        $this->db->readable = false;
        $agent = new LastBrowserTimeZoneTestAgent('42');

        $this->report($agent, 42, 'Europe/Warsaw');

        self::assertSame([], $agent->written);
        self::assertSame(['Last browser time zone for #42 refused by wiring: users is not read here'], $agent->errors);
        self::assertSame([], $agent->sent);
    }

    /** A refused write goes to the log without the zone, and no answer is owed to a browser. */
    public function testARefusedWriteIsLoggedWithoutTheZoneAndAnswersNobody(): void
    {
        $agent = new LastBrowserTimeZoneTestAgent('42');
        $agent->refuseWrite = true;

        $this->report($agent, 42, 'Mars/Olympus_Mons');

        self::assertSame([], $agent->written);
        self::assertSame(
            ['Last browser time zone for #42 not written: The browser time zone is not a valid IANA time zone.'],
            $agent->errors,
        );
        self::assertStringNotContainsString('Mars/Olympus_Mons', $agent->errors[0]);
        self::assertSame([], $agent->sent);
    }

    /**
     * @param int $userId Person the report names
     * @param string $lastBrowserTimeZone Zone the browser named
     * @return AgentSignalData Report as it crosses processes: an untyped payload
     */
    private function untyped(int $userId, string $lastBrowserTimeZone): AgentSignalData
    {
        return new AgentSignalData(new SignalData(['userId' => $userId, 'lastBrowserTimeZone' => $lastBrowserTimeZone]));
    }

    /**
     * Hydrates an untyped report through the declared route and hands it to the agent.
     *
     * @param LastBrowserTimeZoneTestAgent $agent Owner under test
     * @param int $userId Person the report names
     * @param string $lastBrowserTimeZone Zone the browser named
     */
    private function report(LastBrowserTimeZoneTestAgent $agent, int $userId, string $lastBrowserTimeZone): void
    {
        $agent->onSignalAgent(
            new LastBrowserTimeZoneTestRouter()->createAgentSignalPayloadDTO(
                HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE,
                $this->untyped($userId, $lastBrowserTimeZone),
            ),
            '',
            HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE,
        );
    }
}

/** Replaces the row write, the transport and the log; keeps the owner's checks and its outcome handling. */
final class LastBrowserTimeZoneTestAgent extends AbstractUserAgent
{
    public bool $refuseWrite = false;
    /** @var list<string> Zones written, in order */
    public array $written = [];
    /** @var list<string> Lines written to the agent's log */
    public array $errors = [];
    /** @var array<string, SignalDataInterface> Frames queued, by name */
    public array $sent = [];

    /**
     * @param string $signalName Frame name
     * @param SignalDataInterface $data Frame payload
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        $this->sent[$signalName] = $data;
    }

    /**
     * @param string $lastBrowserTimeZone Zone to remember
     * @throws ValidationException When the case refuses the write the way the row refuses an unusable zone
     */
    protected function writeLastBrowserTimeZone(string $lastBrowserTimeZone): void
    {
        if ($this->refuseWrite) {
            throw new ValidationException('The browser time zone is not a valid IANA time zone.');
        }
        $this->written[] = $lastBrowserTimeZone;
    }

    /** @param string $message Failure to record */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

final class LastBrowserTimeZoneTestAgentDaemon extends AbstractUserAgentDaemon
{
}

/** The project topology of the person's agent, as every demo mounts it. */
abstract class LastBrowserTimeZoneTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [
            AgentRegistryKey::WORKER => LastBrowserTimeZoneTestAgent::class,
            AgentRegistryKey::DAEMON => LastBrowserTimeZoneTestAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}

final class LastBrowserTimeZoneTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Topology of the person's agent */
    protected function hilosClass(): string
    {
        return LastBrowserTimeZoneTestHilos::class;
    }
}

/**
 * The person and the merges the owner's check reads, and the memory it compares a report against.
 *
 * The person is readable, there and not folded unless a case says otherwise.
 */
final class LastBrowserTimeZoneTestDb extends HilosDbContext
{
    public bool $readable = true;
    public bool $present = true;
    public bool $merged = false;
    public ?string $stored = null;

    /**
     * @param string $name Collection name
     * @return LastBrowserTimeZoneTestRows Rows of that collection
     * @throws DbCollectionNotReadableException When the case takes away the read of the collection
     */
    public function __get(string $name)
    {
        if (!$this->readable) {
            throw new DbCollectionNotReadableException("{$name} is not read here");
        }

        return new LastBrowserTimeZoneTestRows($name, $this);
    }
}

/** People and merges by key; every other collection holds nothing. */
final class LastBrowserTimeZoneTestRows implements ArrayAccess
{
    /**
     * @param string $name Collection the rows stand for
     * @param LastBrowserTimeZoneTestDb $db State the case set
     */
    public function __construct(private string $name, private LastBrowserTimeZoneTestDb $db)
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        if ($this->name === HilosDbContext::users && $this->db->present) {
            return new LastBrowserTimeZoneTestUser($this->db->stored);
        }
        if ($this->name === HilosDbContext::userMerges && $this->db->merged) {
            return new LastBrowserTimeZoneTestUser(null);
        }

        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

/** A row that carries only the memory the owner compares against; a merge row needs nothing more. */
final class LastBrowserTimeZoneTestUser
{
    /** @param ?string $lastBrowserTimeZone Zone the person's memory holds, or null when it holds none */
    public function __construct(public readonly ?string $lastBrowserTimeZone)
    {
    }
}
