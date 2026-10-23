<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Moderation;

use ArrayAccess;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Exception\LLMException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\LLM\Routing\LlmRouter;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationAgent;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationLlmConstants;
use Hilos\Moderation\ModerationReason;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The moderator over the runtime queue, with a scripted model (HIL-1625).
 *
 * Every case drives the real agent tick by tick against the real checks collection, a scripted
 * client in place of the provider, and a router that records what leaves. Rows change the way a
 * replica of the initiator's writes changes them here - written into the store and announced to the
 * agent as the sync it would receive - so what is tested is the moderator's own choice: which check
 * it takes, that it asks one at a time, that it drops a check whose row moved, that it answers once,
 * and that anything short of a verdict is unavailable.
 *
 * The facade declares moderation and nothing about files or images, so every case is also the
 * text-alone configuration: no image engine is reserved and the image profile is never resolved
 * for text.
 */
final class ModerationAgentTest extends TestCase
{
    public const string INITIATOR = 'unit_moderation_initiator';
    private const string ACCEPT_KEY = 'ak-1';
    private const string OTHER_ACCEPT_KEY = 'ak-2';
    private const int USER_ID = 7;
    private const string PERSON_NAME = 'Person key-7';
    private const int FRESH_FOR_MS = 60000;

    /** @var string App facade class bound before the test, restored afterwards */
    private string $boundAppClass = Hilos::class;

    private ?RtContext $previousRuntime = null;

    private ?HilosDbContext $previousDb = null;

    private ?LlmRouter $previousLlm = null;

    private ?SignalRouter $previousRouter = null;

    private ModerationAgentTestRtContext $runtime;

    private ModerationAgentTestDbContext $db;

    private ModerationAgentTestRouter $llm;

    private ModerationAgentTestSignalRouter $router;

    private ModerationAgentTestAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boundAppClass = Hilos::appClass();
        $this->previousRuntime = Hilos::$rt;
        $this->previousDb = Hilos::$db;
        $this->previousLlm = Hilos::$llm;
        $this->previousRouter = Hilos::$sr;
        self::bindAppClass(ModerationAgentTestHilos::class);

        $this->runtime = new ModerationAgentTestRtContext();
        Hilos::$rt = $this->runtime;
        $this->runtime->configure();
        $this->runtime->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->runtime->connect(self::ACCEPT_KEY, self::USER_ID);
        $this->runtime->connect(self::OTHER_ACCEPT_KEY, self::USER_ID);

        $this->db = new ModerationAgentTestDbContext();
        $this->db->rules = new ModerationAgentTestRules();
        $this->db->people = new ModerationAgentTestPeople([self::USER_ID => self::PERSON_NAME]);
        Hilos::$db = $this->db;

        $this->llm = new ModerationAgentTestRouter();
        $this->llm->profiles[ModerationLlmConstants::PROFILE_TEXT] = self::profile(ModerationLlmConstants::PROFILE_TEXT, 'text-model');
        $this->llm->profiles[ModerationLlmConstants::PROFILE_IMAGE] = self::profile(ModerationLlmConstants::PROFILE_IMAGE, 'image-model');
        Hilos::$llm = $this->llm;

        $this->router = new ModerationAgentTestSignalRouter();
        Hilos::$sr = $this->router;

        $this->agent = new ModerationAgentTestAgent();
    }

    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRuntime;
        Hilos::$db = $this->previousDb;
        Hilos::$llm = $this->previousLlm;
        Hilos::$sr = $this->previousRouter;
        self::bindAppClass($this->boundAppClass);

        parent::tearDown();
    }

    public function testStartsWithoutAnImageEngineAndAsksTheTextProfileAboutText(): void
    {
        $this->agent->onStart();
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'Hello key-7');

        $this->agent->onTick();

        self::assertSame([ModerationLlmConstants::PROFILE_TEXT], $this->llm->resolved);
        $request = $this->client()->requests[0];
        self::assertSame('text-model', $request->options->model);
        self::assertSame(Message::ROLE_SYSTEM, $request->messages[0]->role);
        self::assertStringContainsString('Moderation of: Note.', $request->messages[0]->content);
        self::assertStringContainsString('{"allow":true,"reason":"ok"}', $request->messages[0]->content);
        self::assertStringContainsString('{"allow":false,"reason":"spam"}', $request->messages[0]->content);
        self::assertStringContainsString('- Allow notes.', $request->messages[0]->content);
        self::assertSame(Message::ROLE_USER, $request->messages[1]->role);
        self::assertSame(
            "User ID: 7\n\nUser: " . self::PERSON_NAME . "\n\nText:\nHello key-7",
            $request->messages[1]->content,
        );
    }

    public function testTakesTheOldestFreshCheckAndBreaksATieByRowId(): void
    {
        $now = TimeHelper::nowMs();
        $this->open(self::OTHER_ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-b', text: 'second by id', startedAt: $now - 500);
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-a', text: 'first by id', startedAt: $now - 500);
        $this->open(
            self::ACCEPT_KEY,
            ModerationAgentTestDirectory::OTHER_NOTE,
            'request-expired',
            text: 'oldest but expired',
            startedAt: $now - 900,
            deadlineAt: $now - 1,
        );

        $this->agent->onTick();

        self::assertCount(1, $this->client()->requests);
        self::assertStringEndsWith("Text:\nfirst by id", $this->client()->requests[0]->messages[1]->content);
    }

    public function testAsksOneCheckAtATimeAndTakesTheNextOnceTheAnsweredRowLeaves(): void
    {
        $now = TimeHelper::nowMs();
        $first = $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'first', startedAt: $now - 2);
        $this->open(self::OTHER_ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-2', text: 'second', startedAt: $now - 1);

        $this->agent->onTick();
        $this->agent->onTick();
        $this->agent->onTick();
        self::assertCount(1, $this->client()->requests);

        $this->client()->finish('{"allow":true,"reason":"ok"}');
        $this->agent->onTick();
        $this->agent->onTick();
        self::assertCount(1, $this->client()->requests);
        self::assertCount(1, $this->router->verdicts());

        $this->close($first);
        $this->agent->onTick();

        self::assertCount(2, $this->client()->requests);
        self::assertStringEndsWith("Text:\nsecond", $this->client()->requests[1]->messages[1]->content);
    }

    public function testSendsTheVerdictOnceToTheKindAndNamesTheAttempt(): void
    {
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'spam spam');

        $this->agent->onTick();
        $this->client()->finish('{"allow":false,"reason":"spam"}');
        for ($tick = 0; $tick < 5; $tick++) {
            $this->agent->onTick();
        }

        self::assertCount(1, $this->router->sent);
        self::assertSame(HilosSignalConstants::HILOS_MODERATION_VERDICT, $this->router->sent[0]->name);
        self::assertEquals(
            new ModerationVerdictSignalData(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', false, 'spam'),
            $this->router->sent[0]->data,
        );
    }

    public function testDropsTheAttemptWhenItsRowIsReplacedAndAnswersTheNewOne(): void
    {
        $first = $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'old text');
        $this->agent->onTick();

        $this->close($first);
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-2', text: 'new text');
        self::assertFalse($this->client()->isBusy());

        $this->agent->onTick();
        self::assertCount(2, $this->client()->requests);
        self::assertStringEndsWith("Text:\nnew text", $this->client()->requests[1]->messages[1]->content);
        $this->client()->finish('{"allow":true,"reason":"ok"}');
        $this->agent->onTick();

        self::assertCount(1, $this->router->verdicts());
        self::assertSame('request-2', $this->router->verdicts()[0]->requestId);
    }

    public function testDropsTheAttemptWhenItsRowIsRemoved(): void
    {
        $check = $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'gone soon');
        $this->agent->onTick();

        $this->close($check);
        $this->agent->onTick();
        $this->agent->onTick();

        self::assertGreaterThan(0, $this->client()->resets);
        self::assertCount(1, $this->client()->requests);
        self::assertSame([], $this->router->sent);
    }

    public function testComparesTheRequestIdBeforeSendingAndSendsNothingForAReplacedRow(): void
    {
        $first = $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'old text');
        $this->agent->onTick();
        // The replacement lands in the store, and its announcement has not reached the agent yet.
        $this->runtime->dropCheck($first->getId());
        $this->runtime->putCheck(self::check(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-2', 'new text', null));

        $this->client()->finish('{"allow":true,"reason":"ok"}');
        $this->agent->onTick();

        self::assertSame([], $this->router->sent);
    }

    public function testLeavesAnExpiredCheckAlone(): void
    {
        $now = TimeHelper::nowMs();
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'late', startedAt: $now - 1000, deadlineAt: $now);

        $this->agent->onTick();

        self::assertSame([], $this->agent->clients);
        self::assertSame([], $this->router->sent);
    }

    public function testLetsAnAnsweredCheckGoAtItsDeadlineAndDoesNotTakeItAgain(): void
    {
        $now = TimeHelper::nowMs();
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'short', deadlineAt: $now + 300);
        $this->open(self::OTHER_ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-2', text: 'next', startedAt: $now + 1);
        $this->agent->onTick();
        $this->client()->finish('{"allow":true,"reason":"ok"}');
        $this->agent->onTick();
        self::assertCount(1, $this->client()->requests);

        usleep(350000);
        $this->agent->onTick();
        $this->agent->onTick();

        self::assertCount(2, $this->client()->requests);
        self::assertStringEndsWith("Text:\nnext", $this->client()->requests[1]->messages[1]->content);
        self::assertCount(1, $this->router->verdicts());
    }

    public function testLimitsTheModelTimeoutToWhatIsLeftOfTheDeadline(): void
    {
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'quick', deadlineAt: TimeHelper::nowMs() + 5000);

        $this->agent->onTick();

        $timeoutSec = $this->client()->requests[0]->options->timeoutSec;
        self::assertLessThanOrEqual(5.0, $timeoutSec);
        self::assertGreaterThan(4.0, $timeoutSec);
    }

    /** @return iterable<string, array{string}> Model answers that are no verdict on a note */
    public static function answersThatAreNoVerdict(): iterable
    {
        yield 'not JSON' => ['Looks fine.'];
        yield 'unknown reason' => ['{"allow":false,"reason":"nudity"}'];
        yield 'allowed with a refusal reason' => ['{"allow":true,"reason":"spam"}'];
        yield 'reason missing' => ['{"allow":true}'];
    }

    #[DataProvider('answersThatAreNoVerdict')]
    public function testAnAnswerThatIsNoVerdictIsUnavailable(string $answer): void
    {
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'anything');

        $this->agent->onTick();
        $this->client()->finish($answer);
        $this->agent->onTick();

        $this->assertOneUnavailableVerdict('request-1');
    }

    public function testATransportFailureIsUnavailable(): void
    {
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'anything');

        $this->agent->onTick();
        $this->client()->failure = new LLMException('Connection refused by the provider');
        $this->agent->onTick();

        $this->assertOneUnavailableVerdict('request-1');
    }

    public function testAMissingModelIsUnavailable(): void
    {
        unset($this->llm->profiles[ModerationLlmConstants::PROFILE_TEXT]);
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'anything');

        $this->agent->onTick();

        self::assertSame([], $this->agent->clients);
        $this->assertOneUnavailableVerdict('request-1');
    }

    public function testAKindWithNoRulesIsUnavailable(): void
    {
        $this->db->rules->byKind[ModerationAgentTestDirectory::NOTE] = [];
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'anything');

        $this->agent->onTick();

        self::assertSame([], $this->agent->clients);
        $this->assertOneUnavailableVerdict('request-1');
    }

    public function testAPersonWhoLeftIsUnavailable(): void
    {
        $this->db->people = new ModerationAgentTestPeople([]);
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'anything');

        $this->agent->onTick();

        $this->assertOneUnavailableVerdict('request-1');
    }

    public function testAnImageCheckWhoseUploadIsGoneIsUnavailableWithoutAskingTheModel(): void
    {
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::PHOTO, 'request-1', clientUploadId: 'upload-1');

        $this->agent->onTick();
        $this->agent->onTick();

        self::assertSame([], $this->agent->clients);
        self::assertSame([], $this->llm->resolved);
        $this->assertOneUnavailableVerdict('request-1');
    }

    public function testACheckWhoseConnectionClosedOrChangedHandsIsNotAsked(): void
    {
        $this->runtime->disconnect(self::ACCEPT_KEY);
        $this->runtime->disconnect(self::OTHER_ACCEPT_KEY);
        $this->runtime->connect(self::OTHER_ACCEPT_KEY, self::USER_ID + 1);
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'closed');
        $this->open(self::OTHER_ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-2', text: 'changed hands');

        $this->agent->onTick();

        self::assertSame([], $this->agent->clients);
        self::assertSame([], $this->router->sent);
    }

    public function testReadsRulesAndProfileBeforeEachCheckAndReplacesTheClientOnlyWhenTheProfileChanged(): void
    {
        $first = $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'one');
        $this->agent->onTick();
        $this->client()->finish('{"allow":true,"reason":"ok"}');
        $this->agent->onTick();
        $this->close($first);

        $this->db->rules->byKind[ModerationAgentTestDirectory::NOTE] = ['Block every link.'];
        $second = $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-2', text: 'two');
        $this->agent->onTick();
        self::assertCount(1, $this->agent->clients);
        self::assertStringContainsString('- Block every link.', $this->client()->requests[1]->messages[0]->content);
        self::assertStringNotContainsString('Allow notes.', $this->client()->requests[1]->messages[0]->content);
        $this->client()->finish('{"allow":true,"reason":"ok"}');
        $this->agent->onTick();
        $this->close($second);

        $this->llm->profiles[ModerationLlmConstants::PROFILE_TEXT] = self::profile(ModerationLlmConstants::PROFILE_TEXT, 'bigger-model');
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-3', text: 'three');
        $this->agent->onTick();

        self::assertCount(2, $this->agent->clients);
        self::assertSame('bigger-model', $this->client()->requests[0]->options->model);
        self::assertSame(
            [ModerationLlmConstants::PROFILE_TEXT, ModerationLlmConstants::PROFILE_TEXT, ModerationLlmConstants::PROFILE_TEXT],
            $this->llm->resolved,
        );
    }

    public function testStopDropsTheAttemptInFlight(): void
    {
        $this->open(self::ACCEPT_KEY, ModerationAgentTestDirectory::NOTE, 'request-1', text: 'stopping');
        $this->agent->onTick();

        $this->agent->onStop();

        self::assertFalse($this->client()->isBusy());
        self::assertSame([], $this->router->sent);
    }

    /**
     * Writes a check into the store and announces it to the agent, as a replica of the initiator's write does.
     */
    private function open(
        string $acceptKey,
        string $kind,
        string $requestId,
        ?string $text = null,
        ?string $clientUploadId = null,
        ?int $startedAt = null,
        ?int $deadlineAt = null,
    ): HilosModerationCheck {
        $startedAt ??= TimeHelper::nowMs();
        $check = self::check($acceptKey, $kind, $requestId, $text, $clientUploadId, $startedAt, $deadlineAt ?? $startedAt + self::FRESH_FOR_MS);
        $this->runtime->putCheck($check);
        $this->agent->onSignalRtSyncCreated(
            new RtSyncCreatedSignalData(HilosModerationCheck::RT_COLLECTION, $check->getId(), $check->toArray()),
            'rt',
            'created',
        );

        return $check;
    }

    /**
     * Removes a check from the store and announces it to the agent.
     */
    private function close(HilosModerationCheck $check): void
    {
        $this->runtime->dropCheck($check->getId());
        $this->agent->onSignalRtSyncDeleted(
            new RtSyncDeletedSignalData(HilosModerationCheck::RT_COLLECTION, $check->getId(), $check->toArray()),
            'rt',
            'deleted',
        );
    }

    private static function check(
        string $acceptKey,
        string $kind,
        string $requestId,
        ?string $text,
        ?string $clientUploadId,
        ?int $startedAt = null,
        ?int $deadlineAt = null,
    ): HilosModerationCheck {
        $startedAt ??= TimeHelper::nowMs();

        return HilosModerationCheck::create(
            $acceptKey,
            self::USER_ID,
            $kind,
            $requestId,
            self::INITIATOR,
            $text,
            $clientUploadId,
            $startedAt,
            $deadlineAt ?? $startedAt + self::FRESH_FOR_MS,
        );
    }

    private function assertOneUnavailableVerdict(string $requestId): void
    {
        $verdicts = $this->router->verdicts();
        self::assertCount(1, $verdicts);
        self::assertSame($requestId, $verdicts[0]->requestId);
        self::assertFalse($verdicts[0]->allow);
        self::assertSame(ModerationReason::SERVICE_UNAVAILABLE, $verdicts[0]->reason);
    }

    private function client(): ModerationAgentTestClient
    {
        return $this->agent->clients[array_key_last($this->agent->clients)] ?? self::fail('No client was built');
    }

    private static function profile(string $key, string $model): LlmProfile
    {
        return new LlmProfile($key, LlmProvider::LOCAL, 'http://local', $model, null, 30.0);
    }

    private static function bindAppClass(string $hilosClass): void
    {
        $property = new ReflectionProperty(Hilos::class, 'appClass');
        $property->setValue(null, $hilosClass);
    }
}

/**
 * Two text kinds and an image kind, all answered by the test initiator.
 */
final class ModerationAgentTestDirectory extends ModerationKindDirectory
{
    public const string NOTE = 'unit_note';
    public const string OTHER_NOTE = 'unit_other_note';
    public const string PHOTO = 'unit_photo';

    /** @return list<ModerationKind> Framework kinds followed by the test kinds */
    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            new ModerationKind(self::NOTE, 'Note', ModerationInput::TEXT, ['spam'], ['Allow notes.'], ModerationAgentTest::INITIATOR),
            new ModerationKind(self::OTHER_NOTE, 'Other', ModerationInput::TEXT, ['spam'], ['Allow.'], ModerationAgentTest::INITIATOR),
            new ModerationKind(self::PHOTO, 'Photo', ModerationInput::IMAGE, ['nudity'], ['Allow photos.'], ModerationAgentTest::INITIATOR),
        ];
    }
}

/**
 * A project that moderates and declares nothing about files, uploads or images.
 */
abstract class ModerationAgentTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::MODERATION];

    protected const string MODERATION_KIND_DIRECTORY = ModerationAgentTestDirectory::class;
}

/**
 * The checks, the uploads and a connections collection, with doors for the test to play the replica.
 */
final class ModerationAgentTestRtContext extends RtContext
{
    public const string CONNECTIONS = 'moderationAgentTestConnections';

    public function configure(): void
    {
        new ModerationFeature()->mount($this);
        new UploadsFeature()->mount($this);
        $this->_stateCollections[self::CONNECTIONS] = ModerationAgentTestConnections::init();
    }

    public function putCheck(HilosModerationCheck $check): void
    {
        $this->_stateCollections[HilosModerationCheck::RT_COLLECTION]->add($check);
    }

    public function dropCheck(string $id): void
    {
        $this->_stateCollections[HilosModerationCheck::RT_COLLECTION]->remove($id);
    }

    public function connect(string $acceptKey, int $userId): void
    {
        $this->_stateCollections[self::CONNECTIONS]->add(ModerationAgentTestConnection::create($acceptKey, $userId));
    }

    public function disconnect(string $acceptKey): void
    {
        $this->_stateCollections[self::CONNECTIONS]->remove($acceptKey);
    }
}

final class ModerationAgentTestConnections extends HilosConnections
{
    public const string STATE_CLASS = ModerationAgentTestConnection::class;
}

final class ModerationAgentTestConnection extends HilosConnection
{
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Fields unused by this fixture */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No project fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Fields unused by this fixture */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * A database holding the scripted rules and people and nothing else.
 */
final class ModerationAgentTestDbContext extends HilosDbContext
{
    public ModerationAgentTestRules $rules;

    public ModerationAgentTestPeople $people;

    public function configure(): void
    {
    }

    /**
     * @param string $name Collection name being read
     * @return mixed The scripted rules or people, or what the context holds under any other name
     */
    public function __get(string $name)
    {
        return match ($name) {
            HilosDbContext::moderationRules => $this->rules,
            HilosDbContext::users => $this->people,
            default => parent::__get($name),
        };
    }
}

/**
 * The rules each kind applies, as the rules table would answer.
 */
final class ModerationAgentTestRules
{
    /** @var array<string, list<string>> Rules by kind; a kind left out applies its built-in rules */
    public array $byKind = [];

    /**
     * @return list<string> Rules the kind applies now
     */
    public function effectiveForKind(string $kind): array
    {
        return $this->byKind[$kind] ?? Hilos::moderationKinds()[$kind]->defaultRules;
    }
}

/**
 * People by id, each carrying a name.
 *
 * @implements ArrayAccess<int, ModerationAgentTestPerson>
 */
final class ModerationAgentTestPeople implements ArrayAccess
{
    /** @param array<int, string> $names Names by person id */
    public function __construct(private readonly array $names)
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->names[$offset]);
    }

    public function offsetGet(mixed $offset): ModerationAgentTestPerson
    {
        return new ModerationAgentTestPerson($this->names[$offset]);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

final readonly class ModerationAgentTestPerson
{
    public function __construct(public string $name)
    {
    }
}

/**
 * Router answering each profile key with the profile the case set, recording what was asked.
 */
final class ModerationAgentTestRouter extends LlmRouter
{
    /** @var array<string, LlmProfile> Effective profile by key */
    public array $profiles = [];

    /** @var list<string> Keys resolved, in order */
    public array $resolved = [];

    public function __construct()
    {
    }

    public function resolve(string $profileKey): LlmProfile
    {
        $this->resolved[] = $profileKey;

        return $this->profiles[$profileKey] ?? throw new LLMConfigurationException("No model for {$profileKey}");
    }
}

/**
 * Signal router that keeps what an agent queues instead of routing it.
 */
final class ModerationAgentTestSignalRouter extends SignalRouter
{
    /** @var list<ModerationAgentTestSentSignal> */
    public array $sent = [];

    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        $this->sent[] = new ModerationAgentTestSentSignal(
            $signalName->getName(),
            $signalData instanceof AgentSignalData ? $signalData->data : $signalData,
        );
    }

    /** @return list<ModerationVerdictSignalData> Verdicts sent, in order */
    public function verdicts(): array
    {
        $verdicts = [];
        foreach ($this->sent as $signal) {
            if ($signal->name === HilosSignalConstants::HILOS_MODERATION_VERDICT && $signal->data instanceof ModerationVerdictSignalData) {
                $verdicts[] = $signal->data;
            }
        }

        return $verdicts;
    }
}

final readonly class ModerationAgentTestSentSignal
{
    public function __construct(public string $name, public mixed $data)
    {
    }
}

/**
 * The moderator with every client it builds kept for the case to script.
 */
final class ModerationAgentTestAgent extends ModerationAgent
{
    /** @var list<ModerationAgentTestClient> Clients built, in order */
    public array $clients = [];

    protected function createChatClient(LlmProfile $profile): AsyncChatLLMInterface
    {
        $client = new ModerationAgentTestClient();
        $this->clients[] = $client;

        return $client;
    }
}

final readonly class ModerationAgentTestRequest
{
    /** @param list<Message> $messages Messages the model was asked */
    public function __construct(public array $messages, public ChatGenerateOptions $options)
    {
    }
}

/**
 * A client the case answers by hand.
 */
final class ModerationAgentTestClient implements AsyncChatLLMInterface
{
    /** @var list<ModerationAgentTestRequest> Requests started, in order */
    public array $requests = [];

    public ?LLMException $failure = null;

    public int $resets = 0;

    private bool $busy = false;

    private ?string $result = null;

    /** @param list<Message> $messages Messages to generate from */
    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        $this->requests[] = new ModerationAgentTestRequest($messages, $options);
        $this->busy = true;
        $this->result = null;
    }

    public function tick(float $currentTimeMs): void
    {
        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;
            $this->busy = false;
            throw $failure;
        }
    }

    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    public function consumeResult(): string
    {
        $result = $this->result ?? throw new LLMException('No result');
        $this->result = null;

        return $result;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function reset(): void
    {
        $this->busy = false;
        $this->result = null;
        $this->resets++;
    }

    public function finish(string $text): void
    {
        $this->busy = false;
        $this->result = $text;
    }
}
