<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Hilos;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The moderation verdict goes to the agent its kind names (HIL-1625).
 *
 * No agent declares the verdict: the people library takes the verdicts of a profile photo, the
 * chat agent those of a message, and one signal cannot have both as its static owner. So the
 * router reads the kind out of the verdict and asks the project's kind directory where it goes.
 * The cases hold the two ends of that: each kind reaches the agent it names and nothing reaches
 * anybody for a kind, a payload or a source the router does not accept; the recipient is placed
 * like any declared one; the receiving worker gets the verdict typed with its receipt. Every case
 * runs with a database and a runtime that refuse any read, because the router runs in the master.
 */
final class SignalRouterModerationVerdictTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-verdict';
    private const string REQUEST_ID = 'request-1';
    private const string REASON = 'spam';
    private const int RECEIPT_ID = 42;
    private const string OTHER_NODE = 'node-B';

    /** @var string App facade class bound before the test, restored afterwards */
    private string $boundAppClass = Hilos::class;

    private ?HilosDbContext $previousDb = null;

    private ?RtContext $previousRuntime = null;

    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boundAppClass = Hilos::appClass();
        $this->previousDb = Hilos::$db;
        $this->previousRuntime = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        self::bindAppClass(ModerationVerdictRoutingTestHilos::class);
        // Neither context mounts a collection, so any read of either throws instead of answering.
        Hilos::$db = new ModerationVerdictRoutingTestDbContext();
        Hilos::$rt = new ModerationVerdictRoutingTestRtContext();
        Hilos::$cluster = null;
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRuntime;
        Hilos::$db = $this->previousDb;
        self::bindAppClass($this->boundAppClass);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}> Kind key and the agent its verdicts go to
     */
    public static function kindsAndTheirRecipients(): array
    {
        return [
            'a project kind' => [ModerationVerdictRoutingTestDirectory::NOTE, ModerationVerdictRoutingTestDirectory::NOTE_RECIPIENT],
            'another project kind' => [
                ModerationVerdictRoutingTestDirectory::PICTURE,
                ModerationVerdictRoutingTestDirectory::PICTURE_RECIPIENT,
            ],
            'a framework kind' => [ModerationKindKey::PROFILE_NAME, HilosAgentType::HILOS_USERS_LIBRARY],
        ];
    }

    #[DataProvider('kindsAndTheirRecipients')]
    public function testEachKindSendsItsVerdictToTheAgentItNames(string $kind, string $recipient): void
    {
        $destinations = new ModerationVerdictRoutingTestRouter()->getDestinations(
            self::verdictSignal(new AgentSignalData(self::verdict($kind))),
        );

        $this->assertEquals([new AgentDestination($recipient)], $destinations);
    }

    /**
     * The worker parses a verdict sent as loose fields before its recipient reads it, so the
     * router reads the same fields the same way rather than refusing what would be delivered.
     */
    public function testAVerdictSentAsLooseFieldsIsReadBeforeItIsRouted(): void
    {
        $destinations = new ModerationVerdictRoutingTestRouter()->getDestinations(self::verdictSignal(
            new AgentSignalData(new SignalData(self::verdict(ModerationVerdictRoutingTestDirectory::PICTURE)->toArray())),
        ));

        $this->assertEquals([new AgentDestination(ModerationVerdictRoutingTestDirectory::PICTURE_RECIPIENT)], $destinations);
    }

    public function testAKindTheProjectDoesNotDeclareIsNotRouted(): void
    {
        $destinations = new ModerationVerdictRoutingTestRouter()->getDestinations(
            self::verdictSignal(new AgentSignalData(self::verdict('undeclared_kind'))),
        );

        $this->assertSame([], $destinations);
    }

    /**
     * The directory declares the photo kind in every project, and publishes it only to one that
     * takes profile photos; the route asks what is published, not what is declared.
     */
    public function testThePhotoKindIsNotRoutedWhileTheProjectTakesNoPhotos(): void
    {
        $destinations = new ModerationVerdictRoutingTestRouter()->getDestinations(
            self::verdictSignal(new AgentSignalData(self::verdict(ModerationKindKey::PROFILE_PHOTO))),
        );

        $this->assertSame([], $destinations);
    }

    /**
     * @return array<string, array{SignalDataInterface}> Signal data no verdict can be read out of
     */
    public static function payloadsThatAreNotAVerdict(): array
    {
        $verdictFields = new ModerationVerdictSignalData(
            self::ACCEPT_KEY,
            ModerationVerdictRoutingTestDirectory::NOTE,
            self::REQUEST_ID,
            false,
            self::REASON,
        )->toArray();
        unset($verdictFields[ModerationVerdictSignalData::requestId]);

        return [
            'a verdict missing its request id' => [new AgentSignalData(new SignalData($verdictFields))],
            'a verdict outside the agent envelope' => [new SignalData($verdictFields)],
        ];
    }

    #[DataProvider('payloadsThatAreNotAVerdict')]
    public function testAPayloadThatIsNotAVerdictIsNotRouted(SignalDataInterface $data): void
    {
        $destinations = new ModerationVerdictRoutingTestRouter()->getDestinations(self::verdictSignal($data));

        $this->assertSame([], $destinations);
    }

    public function testAVerdictFromASourceTheRouterDoesNotAcceptIsNotRouted(): void
    {
        $destinations = new ModerationVerdictRoutingTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(HilosSignalConstants::HILOS_MODERATION_VERDICT),
            new AgentSignalData(self::verdict(ModerationVerdictRoutingTestDirectory::NOTE)),
        ));

        $this->assertSame([], $destinations);
    }

    /**
     * Every case runs with these contexts; this one shows that they really refuse, so a route
     * that read either would have failed rather than answered out of an empty store.
     */
    public function testRoutingReadsNeitherTheDatabaseNorTheRuntime(): void
    {
        $destinations = new ModerationVerdictRoutingTestRouter()->getDestinations(
            self::verdictSignal(new AgentSignalData(self::verdict(ModerationVerdictRoutingTestDirectory::NOTE))),
        );

        $this->assertEquals([new AgentDestination(ModerationVerdictRoutingTestDirectory::NOTE_RECIPIENT)], $destinations);
        $this->assertDatabaseRefusesReads();
        $this->assertRuntimeRefusesReads();
    }

    public function testTheRecipientIsPlacedLikeAnyDeclaredAgent(): void
    {
        $context = new ClusterContext();
        $context->registerWorkerPlacement(new ModerationVerdictRoutingTestPlacement([
            ModerationVerdictRoutingTestDirectory::PICTURE_RECIPIENT => AgentLocation::onNode(self::OTHER_NODE),
        ]));
        Hilos::$cluster = $context;
        $router = new ModerationVerdictRoutingTestRouter();

        $this->assertEquals(
            [new RemoteAgentDestination(self::OTHER_NODE, ModerationVerdictRoutingTestDirectory::PICTURE_RECIPIENT)],
            $router->getDestinations(
                self::verdictSignal(new AgentSignalData(self::verdict(ModerationVerdictRoutingTestDirectory::PICTURE))),
            ),
        );
        $this->assertEquals(
            [new AgentDestination(ModerationVerdictRoutingTestDirectory::NOTE_RECIPIENT)],
            $router->getDestinations(self::verdictSignal(new AgentSignalData(self::verdict(ModerationVerdictRoutingTestDirectory::NOTE)))),
        );
    }

    public function testTheReceivingWorkerGetsTheVerdictTypedWithItsReceipt(): void
    {
        $sent = self::verdict(ModerationVerdictRoutingTestDirectory::NOTE);

        $parsed = new ModerationVerdictRoutingTestRouter()->createAgentSignalPayloadDTO(
            HilosSignalConstants::HILOS_MODERATION_VERDICT,
            new AgentSignalData(new SignalData($sent->toArray()), self::RECEIPT_ID),
        );

        $this->assertInstanceOf(ModerationVerdictSignalData::class, $parsed->data);
        $this->assertSame($sent->toArray(), $parsed->data->toArray());
        $this->assertSame(self::RECEIPT_ID, $parsed->receiptId);
    }

    public function testATypedVerdictReachesTheWorkerUnchanged(): void
    {
        $signalData = new AgentSignalData(self::verdict(ModerationVerdictRoutingTestDirectory::NOTE), self::RECEIPT_ID);

        $parsed = new ModerationVerdictRoutingTestRouter()->createAgentSignalPayloadDTO(
            HilosSignalConstants::HILOS_MODERATION_VERDICT,
            $signalData,
        );

        $this->assertSame($signalData, $parsed);
    }

    public function testAPayloadThatIsNotAVerdictIsRefusedAtTheReceivingWorker(): void
    {
        $this->expectException(InvalidAgentSignalPayloadException::class);
        $this->expectExceptionMessage(ModerationVerdictSignalData::class);

        new ModerationVerdictRoutingTestRouter()->createAgentSignalPayloadDTO(
            HilosSignalConstants::HILOS_MODERATION_VERDICT,
            new AgentSignalData(new SignalData([ModerationVerdictSignalData::kind => ModerationVerdictRoutingTestDirectory::NOTE])),
        );
    }

    private function assertDatabaseRefusesReads(): void
    {
        try {
            Hilos::$db?->users;
            $this->fail('The database context answered a read');
        } catch (CollectionNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertRuntimeRefusesReads(): void
    {
        try {
            Hilos::$rt?->hilosModerationChecks;
            $this->fail('The runtime context answered a read');
        } catch (RtCollectionNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param string $kind Kind key the verdict names
     */
    private static function verdict(string $kind): ModerationVerdictSignalData
    {
        return new ModerationVerdictSignalData(self::ACCEPT_KEY, $kind, self::REQUEST_ID, false, self::REASON);
    }

    /**
     * @param SignalDataInterface $data Signal data the moderator sends
     */
    private static function verdictSignal(SignalDataInterface $data): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(HilosSignalConstants::HILOS_MODERATION_VERDICT),
            $data,
        );
    }

    /**
     * @param string $hilosClass Facade class to bind as the running project
     */
    private static function bindAppClass(string $hilosClass): void
    {
        $property = new ReflectionProperty(Hilos::class, 'appClass');
        $property->setValue(null, $hilosClass);
    }
}

final class ModerationVerdictRoutingTestRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return ModerationVerdictRoutingTestHilos::class;
    }
}

/**
 * Two project kinds whose verdicts go to two different agents, beside the framework's own.
 */
final class ModerationVerdictRoutingTestDirectory extends ModerationKindDirectory
{
    public const string NOTE = 'routing_note';
    public const string NOTE_RECIPIENT = 'routing_note_owner';
    public const string PICTURE = 'routing_picture';
    public const string PICTURE_RECIPIENT = 'routing_picture_owner';

    /**
     * @return list<ModerationKind> Framework kinds followed by the two project kinds
     */
    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            new ModerationKind(self::NOTE, 'Note', ModerationInput::TEXT, ['spam'], ['Allow notes.'], self::NOTE_RECIPIENT),
            new ModerationKind(self::PICTURE, 'Picture', ModerationInput::IMAGE, ['nudity'], ['Allow pictures.'], self::PICTURE_RECIPIENT),
        ];
    }
}

/**
 * Facade standing in for a project that moderates and takes no profile photos; no agent of it
 * declares the verdict, and no layer is built from it.
 */
abstract class ModerationVerdictRoutingTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::MODERATION];

    protected const string MODERATION_KIND_DIRECTORY = ModerationVerdictRoutingTestDirectory::class;
}

/**
 * A database with no collection mounted: every read of it throws.
 */
final class ModerationVerdictRoutingTestDbContext extends HilosDbContext
{
    /**
     * Mounts nothing.
     */
    public function configure(): void
    {
    }
}

/**
 * A runtime with no collection mounted: every read of it throws.
 */
final class ModerationVerdictRoutingTestRtContext extends RtContext
{
    /**
     * Mounts nothing.
     */
    public function configure(): void
    {
    }
}

/**
 * Placement lookup that names the node of the agents it was given and answers "here" for any other.
 */
final readonly class ModerationVerdictRoutingTestPlacement implements WorkerPlacement
{
    /**
     * @param array<string, AgentLocation> $placements Agent type to the location it is at
     */
    public function __construct(private array $placements)
    {
    }

    /**
     * @param string $agentType Agent type to locate
     * @param ?string $agentIndex Agent index, unused: the recipients are singletons
     * @return AgentLocation Where the agent runs
     */
    public function locate(string $agentType, ?string $agentIndex): AgentLocation
    {
        return $this->placements[$agentType] ?? AgentLocation::here();
    }
}
