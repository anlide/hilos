<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Item\ModerationRule;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\DTO\MessageImage;
use Hilos\LLM\Exception\LLMException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\LLM\Routing\LlmRouter;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationAgent;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationLlmConstants;
use Hilos\Moderation\ModerationReason;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosModerationCheck;
use Hilos\Testing\IntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Throwable;

/**
 * Unmarked environment owner: beside the parent's person and settings tables it stands the rules
 * table, and around them everything the moderator reads in a node - the settings accessor, the LLM
 * router with the framework's override, the runtime queue and the uploads, a temporary directory
 * the image comes from - with the claims of the agents that write each of them, and a log file of
 * the case's own for what the moderator writes down.
 */
abstract class ModerationAgentIntegrationEnvironment extends FrameworkRollbackIntegrationTestCase
{
    /** The rules table, created from its migration stub beside the parent's tables. */
    private const string RULES_TABLE = 'hilos_moderation_rule';

    /** @var class-string<Hilos> Facade class bound before the case */
    private string $previousAppClass = Hilos::class;
    private ?SettingsAccessor $previousSetting = null;
    private ?LlmRouter $previousLlm = null;
    private ?FsContext $previousFs = null;
    /** @var string|false External API key the process environment held before the case, false when none */
    private string|false $previousExternalKey = false;
    /** Whether the parent's connection and schema came up, so this environment's own SQL may run */
    private bool $databaseReady = false;
    private bool $rulesTableCreated = false;
    /** Temporary directory of the case, or null until it is chosen */
    private ?string $tmpPath = null;
    /** Log file the moderator writes why a check went unavailable, or null until it is chosen */
    private ?string $logFile = null;

    /**
     * Keeps what the case replaces, adds the rules table to the parent's schema and mounts the
     * moderator's surroundings; every case starts with no external API key in the environment.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousSetting = Hilos::$setting;
        $this->previousLlm = Hilos::$llm;
        $this->previousFs = Hilos::$fs;
        $this->previousExternalKey = getenv(EnvConstants::LLM_EXTERNAL_API_KEY->name);
        parent::prepareIntegrationEnvironment();
        $this->databaseReady = true;
        self::runRulesStub(down: false);
        $this->rulesTableCreated = true;
        Schema::reset();
        Schema::initialize();

        self::bindAppClass(ModerationAgentIntegrationHilos::class);
        putenv(EnvConstants::LLM_EXTERNAL_API_KEY->name . '=');
        Hilos::$setting = new SettingsAccessor();
        Hilos::$llm = new LlmRouter();
        Hilos::$sr = new SignalRouter();
        $this->tmpPath = sys_get_temp_dir() . '/hilos-moderation-' . bin2hex(random_bytes(6));
        Hilos::$fs = new ModerationAgentIntegrationFsContext($this->tmpPath);
        Hilos::$fs->configure();
        $this->logFile = $this->tmpPath . '.log';
        Logger::setLogFile($this->logFile);
        // A removed check leaves its wrapper in the view cache without this subscriber.
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $runtime = new ModerationAgentIntegrationRtContext();
        $runtime->configure();
        $runtime->bindStateCollectionNames();
        Hilos::$rt = $runtime;

        TruthSourceRegistry::register(
            HilosDbContext::moderationRules,
            TruthSourceKeys::all(),
            HilosAgentType::HILOS_MODERATION_RULES_LIBRARY,
        );
        RtTruthSourceRegistry::register(
            StateHilosModerationCheck::RT_COLLECTION,
            TruthSourceKeys::set(HilosAgentType::HILOS_USERS_LIBRARY),
            HilosAgentType::HILOS_USERS_LIBRARY,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove),
        );
        RtTruthSourceRegistry::register(StateHilosUpload::RT_COLLECTION, TruthSourceKeys::all(), HilosAgentType::HILOS_UPLOADS);
    }

    /**
     * Drops the rules table, deletes the temporary files, takes the claims back and restores what
     * the case replaced, and lets the parent release in every outcome; the first failure is
     * reported after all of it ran.
     */
    protected function releaseIntegrationEnvironment(): void
    {
        $failures = [];
        TruthSourceRegistry::unregister(HilosDbContext::moderationRules, HilosAgentType::HILOS_MODERATION_RULES_LIBRARY);
        RtTruthSourceRegistry::unregister(StateHilosModerationCheck::RT_COLLECTION, HilosAgentType::HILOS_USERS_LIBRARY);
        RtTruthSourceRegistry::unregister(StateHilosUpload::RT_COLLECTION, HilosAgentType::HILOS_UPLOADS);
        SourceChangeBus::reset();
        Logger::resetLogFile();
        if ($this->databaseReady && $this->rulesTableCreated) {
            try {
                Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                self::runRulesStub(down: true);
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }
        $this->rulesTableCreated = false;
        $this->databaseReady = false;
        if ($this->tmpPath !== null) {
            foreach (glob($this->tmpPath . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($this->tmpPath)) {
                rmdir($this->tmpPath);
            }
            $this->tmpPath = null;
        }
        if ($this->logFile !== null && is_file($this->logFile)) {
            unlink($this->logFile);
        }
        $this->logFile = null;
        $name = EnvConstants::LLM_EXTERNAL_API_KEY->name;
        putenv($this->previousExternalKey === false ? $name : "{$name}={$this->previousExternalKey}");
        Hilos::$fs = $this->previousFs;
        Hilos::$llm = $this->previousLlm;
        Hilos::$setting = $this->previousSetting;
        self::bindAppClass($this->previousAppClass);
        try {
            parent::releaseIntegrationEnvironment();
        } catch (Throwable $failure) {
            $failures[] = $failure;
        }
        if ($failures !== []) {
            throw $failures[0];
        }
    }

    /** @param bool $down Whether to execute the stub's drop statement instead of its create statement */
    private static function runRulesStub(bool $down): void
    {
        $name = 'create_' . self::RULES_TABLE;
        if ($down) {
            $name .= '_down';
        }
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $name . '.sql'));
    }

    /** @param class-string<Hilos> $hilosClass Facade class to bind as the running project */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/**
 * The cluster moderator against the rules table, the settings and the queue as a node keeps them
 * (HIL-1625): a rule an administrator saved, edited or removed reaches the prompt of the next check,
 * a saved model, address, timeout or provider of either role reaches the client the next check is
 * asked through while the check already with the model finishes on the profile it started with,
 * and a check the model cannot be asked about or does not answer ends unavailable - and a setting
 * put right lets the next check through without restarting the moderator.
 *
 * Real: the rules table and its writer's claim, the settings table read through the ordinary
 * accessor, the LLM router with the framework's settings override, the people table, the queue
 * opened through its own actions under its initiator's set, a complete upload whose file the
 * moderator reads off disk, and the signal router the verdict is queued on. Doubled: the provider
 * alone, through the agent's own seam {@see ModerationAgent::createChatClient()} - the async client
 * the case answers by hand, which is the boundary the moderator pumps on every tick.
 *
 * The moderator runs under its own agent id and holds no claim, so any write of its own would be
 * refused. The rollback scope holds the queue's announcements for a commit that never comes, so
 * the case hands the moderator each sync frame its worker would receive, as a replica would.
 */
#[IntegrationTest]
final class ModerationAgentIntegrationTest extends ModerationAgentIntegrationEnvironment
{
    private const string ACCEPT_KEY = 'accept-moderation';
    private const int USER_ID = 1625;
    private const string PERSON_NAME = 'Person 1625';
    private const string NAME = 'Anna Nowak';
    private const string UPLOAD_ID = 'photo-1625';
    private const string PHOTO_TYPE = 'image/png';
    private const string PHOTO_BYTES = "\x89PNG\r\n\x1a\nnot a real picture";
    private const string ALLOWED = '{"allow":true,"reason":"ok"}';
    private const string SAVED_MODEL = 'moderation-model-saved';
    private const string SAVED_URL = 'http://moderation.test:11434';
    private const float SAVED_TIMEOUT_SEC = 7.5;
    private const string EXTERNAL_KEY = 'moderation-test-key';

    private ModerationAgentIntegrationAgent $agent;

    /** @var list<ModerationVerdictSignalData> Verdicts taken off the signal queue and not yet asserted */
    private array $verdicts = [];

    /**
     * @return iterable<string, array{ModerationAgentIntegrationRole}> The two roles, each with the framework kind it judges
     */
    public static function roles(): iterable
    {
        yield 'text' => [new ModerationAgentIntegrationRole(
            ModerationKindKey::PROFILE_NAME,
            ModerationLlmConstants::PROFILE_TEXT,
            ModerationSettingsCatalog::TEXT_PROVIDER,
            ModerationSettingsCatalog::TEXT_MODEL,
            ModerationSettingsCatalog::TEXT_URL,
            ModerationSettingsCatalog::TEXT_TIMEOUT_SEC,
        )];
        yield 'image' => [new ModerationAgentIntegrationRole(
            ModerationKindKey::PROFILE_PHOTO,
            ModerationLlmConstants::PROFILE_IMAGE,
            ModerationSettingsCatalog::IMAGE_PROVIDER,
            ModerationSettingsCatalog::IMAGE_MODEL,
            ModerationSettingsCatalog::IMAGE_URL,
            ModerationSettingsCatalog::IMAGE_TIMEOUT_SEC,
        )];
    }

    #[DataProvider('roles')]
    public function testAnOwnRuleReachesThePromptAndItsEditOrRemovalReachesTheNextCheck(ModerationAgentIntegrationRole $role): void
    {
        $rule = $this->asRulesLibrary(
            static fn (): ModerationRule => Hilos::$db->moderationRules->actions->create($role->kind, 'Only what a colleague may see.'),
        );
        $first = $this->openCheck($role);
        $this->tick();
        self::assertStringEndsWith("Rules:\n- Only what a colleague may see.", $this->model()->requests[0]->messages[0]->content);

        // An edit while the check is with the model neither drops it nor changes what it was asked.
        $this->asRulesLibrary(static fn () => $rule->actions->update('Nothing a colleague would mind.'));
        $this->model()->answer(self::ALLOWED);
        $this->tick();
        $this->assertVerdict($first, true, ModerationReason::OK);
        $this->close($first);

        $second = $this->openCheck($role);
        $this->tick();
        self::assertStringEndsWith("Rules:\n- Nothing a colleague would mind.", $this->model()->requests[1]->messages[0]->content);
        $this->model()->answer(self::ALLOWED);
        $this->tick();
        $this->close($second);

        $this->asRulesLibrary(static fn () => $rule->actions->delete());
        $this->openCheck($role);
        $this->tick();
        $builtIn = array_map(static fn (string $text): string => "- {$text}", Hilos::moderationKinds()[$role->kind]->defaultRules);
        self::assertStringEndsWith("Rules:\n" . implode("\n", $builtIn), $this->model()->requests[2]->messages[0]->content);
        self::assertCount(1, $this->agent->clients);
    }

    #[DataProvider('roles')]
    public function testEachRoleTakesItsSavedSettingsOnTheNextCheckWhileTheOneInFlightKeepsItsOwn(
        ModerationAgentIntegrationRole $role,
    ): void {
        $first = $this->openCheck($role);
        $this->tick();
        $started = $this->model()->profile;
        self::assertSame($role->profileKey, $started->key);
        self::assertSame(LlmProvider::LOCAL, $started->provider);
        self::assertNotSame(self::SAVED_MODEL, $started->model);
        $this->assertAsked($role, $this->model()->requests[0]);

        $this->save($role->modelKey, self::SAVED_MODEL);
        $this->save($role->urlKey, self::SAVED_URL);
        $this->save($role->timeoutKey, self::SAVED_TIMEOUT_SEC);
        $this->tick();
        $this->model()->answer(self::ALLOWED);
        $this->tick();
        self::assertCount(1, $this->agent->clients);
        self::assertSame($started->model, $this->model()->requests[0]->options->model);
        $this->assertVerdict($first, true, ModerationReason::OK);
        $this->close($first);

        $second = $this->openCheck($role);
        $this->tick();
        self::assertCount(2, $this->agent->clients);
        self::assertEquals(
            new LlmProfile($role->profileKey, LlmProvider::LOCAL, self::SAVED_URL, self::SAVED_MODEL, null, self::SAVED_TIMEOUT_SEC),
            $this->model()->profile,
        );
        self::assertSame(self::SAVED_MODEL, $this->model()->requests[0]->options->model);
        self::assertSame(self::SAVED_TIMEOUT_SEC, $this->model()->requests[0]->options->timeoutSec);
        $this->assertAsked($role, $this->model()->requests[0]);

        putenv(EnvConstants::LLM_EXTERNAL_API_KEY->name . '=' . self::EXTERNAL_KEY);
        $this->save($role->providerKey, LLMConstants::PROVIDER_EXTERNAL);
        $this->tick();
        $this->model()->answer(self::ALLOWED);
        $this->tick();
        self::assertCount(2, $this->agent->clients);
        $this->assertVerdict($second, true, ModerationReason::OK);
        $this->close($second);

        $this->openCheck($role);
        $this->tick();
        self::assertCount(3, $this->agent->clients);
        self::assertEquals(
            new LlmProfile(
                $role->profileKey,
                LlmProvider::EXTERNAL,
                Hilos::$env[EnvConstants::LLM_EXTERNAL_URL]->string(),
                self::SAVED_MODEL,
                self::EXTERNAL_KEY,
                self::SAVED_TIMEOUT_SEC,
            ),
            $this->model()->profile,
        );
    }

    #[DataProvider('roles')]
    public function testAProviderLeftWithoutItsKeyIsUnavailableUntilTheSettingIsPutRightWithoutARestart(
        ModerationAgentIntegrationRole $role,
    ): void {
        $this->save($role->providerKey, LLMConstants::PROVIDER_EXTERNAL);
        $refused = $this->openCheck($role);
        $this->tick();
        self::assertSame([], $this->agent->clients);
        $this->assertVerdict($refused, false, ModerationReason::SERVICE_UNAVAILABLE);
        $this->close($refused);

        $this->save($role->providerKey, LLMConstants::PROVIDER_LOCAL);
        $checked = $this->openCheck($role);
        $this->tick();
        $this->model()->answer(self::ALLOWED);
        $this->tick();

        self::assertSame(LlmProvider::LOCAL, $this->model()->profile->provider);
        $this->assertAsked($role, $this->model()->requests[0]);
        $this->assertVerdict($checked, true, ModerationReason::OK);
    }

    #[DataProvider('roles')]
    public function testAModelThatDoesNotAnswerIsUnavailableAndTheNextCheckIsAskedAgain(ModerationAgentIntegrationRole $role): void
    {
        $silent = $this->openCheck($role);
        $this->tick();
        $this->model()->fail(new LLMException('The model did not answer within the timeout'));
        $this->tick();
        $this->assertVerdict($silent, false, ModerationReason::SERVICE_UNAVAILABLE);
        $this->close($silent);

        $next = $this->openCheck($role);
        $this->tick();
        $this->model()->answer(self::ALLOWED);
        $this->tick();

        self::assertCount(1, $this->agent->clients);
        self::assertCount(2, $this->model()->requests);
        $this->assertVerdict($next, true, ModerationReason::OK);
    }

    /** The person whose content is checked, on an open connection, with a complete photo upload. */
    protected function setUpInTransaction(): void
    {
        Database::sqlRun('INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, ?)', [self::USER_ID, self::PERSON_NAME]);
        $runtime = Hilos::$rt;
        self::assertInstanceOf(ModerationAgentIntegrationRtContext::class, $runtime);
        $runtime->connect(self::ACCEPT_KEY, self::USER_ID);

        $this->as(HilosAgentType::HILOS_UPLOADS, static function (): void {
            $tmp = Hilos::$fs?->getTmp() ?? self::fail('The case has no temporary directory');
            $index = $tmp->create();
            $tmp[$index]->append(self::PHOTO_BYTES);
            Hilos::$rt->hilosUploads->actions->open(
                self::ACCEPT_KEY,
                self::UPLOAD_ID,
                ProfilePhotoUploadTarget::NAME,
                self::USER_ID,
                'photo.png',
                self::PHOTO_TYPE,
                strlen(self::PHOTO_BYTES),
                $index,
            )->actions->complete();
        });

        $this->agent = new ModerationAgentIntegrationAgent();
        $this->as($this->agent->getId(), fn () => $this->agent->onStart());
    }

    /**
     * Opens a check of the role's kind as the people library does, and hands the moderator its sync.
     */
    private function openCheck(ModerationAgentIntegrationRole $role): HilosModerationCheck
    {
        $image = Hilos::moderationKinds()[$role->kind]->input === ModerationInput::IMAGE;
        $check = $this->as(
            HilosAgentType::HILOS_USERS_LIBRARY,
            static fn (): HilosModerationCheck => $image
                ? Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, self::USER_ID, $role->kind, clientUploadId: self::UPLOAD_ID)
                : Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, self::USER_ID, $role->kind, self::NAME),
        );
        $sync = new RtSyncCreatedSignalData(StateHilosModerationCheck::RT_COLLECTION, $check->getId(), $check->toArray());
        $this->as($this->agent->getId(), fn () => $this->agent->onSignalRtSyncCreated($sync, 'rt', 'created'));

        return $check;
    }

    /**
     * Ends a check as its initiator does once the verdict is applied, and hands the moderator its sync.
     */
    private function close(HilosModerationCheck $check): void
    {
        $row = $check->toArray();
        $this->as(HilosAgentType::HILOS_USERS_LIBRARY, static fn () => $check->actions->forget());
        $sync = new RtSyncDeletedSignalData(StateHilosModerationCheck::RT_COLLECTION, $check->getId(), $row);
        $this->as($this->agent->getId(), fn () => $this->agent->onSignalRtSyncDeleted($sync, 'rt', 'deleted'));
    }

    /** One tick of the moderator under its own agent id, and the verdicts it queued taken off the router. */
    private function tick(): void
    {
        $this->as($this->agent->getId(), fn () => $this->agent->onTick());
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_MODERATION_VERDICT) {
                continue;
            }
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(ModerationVerdictSignalData::class, $signal->data->data);
            $this->verdicts[] = $signal->data->data;
        }
    }

    /**
     * Saves a moderation setting as an administrator does: the row is added, or its value replaced.
     */
    private function save(string $key, string|float $value): void
    {
        if (isset(Hilos::$db->settings[$key])) {
            Hilos::$db->settings[$key]->actions->updateValue($value);

            return;
        }
        Hilos::$db->settings->actions->add($key, $value, Hilos::$setting?->catalog() ?? self::fail('No settings accessor'));
    }

    /**
     * @template T
     * @param Closure(): T $write Write of the rules library
     * @return T What the write returns
     */
    private function asRulesLibrary(Closure $write): mixed
    {
        return $this->as(HilosAgentType::HILOS_MODERATION_RULES_LIBRARY, $write);
    }

    /**
     * Runs a step under an agent's id, the way its worker calls it.
     *
     * @template T
     * @param string $agentId Agent the step runs as
     * @param Closure(): T $step Step to run
     * @return T What the step returns
     */
    private function as(string $agentId, Closure $step): mixed
    {
        $token = ExecutionContext::push(new ExecutionFrame(agentId: $agentId));
        try {
            return $step();
        } finally {
            ExecutionContext::pop($token);
        }
    }

    /**
     * Asserts the one verdict queued since the last one asserted, answering the check by its request id.
     */
    private function assertVerdict(HilosModerationCheck $check, bool $allow, string $reason): void
    {
        self::assertEquals(
            [new ModerationVerdictSignalData(self::ACCEPT_KEY, $check->kind, $check->requestId, $allow, $reason)],
            $this->verdicts,
        );
        $this->verdicts = [];
    }

    /**
     * Asserts the person's content the model was asked about: the name as text, or the photo read off disk.
     */
    private function assertAsked(ModerationAgentIntegrationRole $role, ModerationAgentIntegrationRequest $request): void
    {
        $context = 'User ID: ' . self::USER_ID . "\n\nUser: " . self::PERSON_NAME;
        $kind = Hilos::moderationKinds()[$role->kind];
        self::assertSame(Message::ROLE_SYSTEM, $request->messages[0]->role);
        self::assertStringStartsWith("Moderation of: {$kind->title}. JSON only.", $request->messages[0]->content);
        self::assertSame(Message::ROLE_USER, $request->messages[1]->role);
        if ($kind->input === ModerationInput::IMAGE) {
            self::assertSame($context, $request->messages[1]->content);
            self::assertEquals([new MessageImage(self::PHOTO_TYPE, base64_encode(self::PHOTO_BYTES))], $request->messages[1]->images);

            return;
        }
        self::assertSame($context . "\n\nText:\n" . self::NAME, $request->messages[1]->content);
        self::assertSame([], $request->messages[1]->images);
    }

    /** @return ModerationAgentIntegrationModel The client the moderator built last */
    private function model(): ModerationAgentIntegrationModel
    {
        return $this->agent->clients[array_key_last($this->agent->clients)] ?? self::fail('The moderator built no client');
    }
}

/**
 * A project that moderates and takes profile photos, so both framework kinds are published.
 */
abstract class ModerationAgentIntegrationHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::MODERATION, HilosFeature::PROFILE_PHOTO];
}

/**
 * One moderator role: the framework kind it judges, its LLM profile and the four settings keys of that profile.
 */
final readonly class ModerationAgentIntegrationRole
{
    /**
     * @param string $kind Framework kind the role judges
     * @param string $profileKey LLM profile of the role
     * @param string $providerKey Provider setting of the profile
     * @param string $modelKey Model setting of the profile
     * @param string $urlKey Local address setting of the profile
     * @param string $timeoutKey Request timeout setting of the profile
     */
    public function __construct(
        public string $kind,
        public string $profileKey,
        public string $providerKey,
        public string $modelKey,
        public string $urlKey,
        public string $timeoutKey,
    ) {
    }
}

/**
 * The checks queue, the uploads and the connections, as the moderation and uploads features mount them.
 */
final class ModerationAgentIntegrationRtContext extends RtContext
{
    public function configure(): void
    {
        new ModerationFeature()->mount($this);
        new UploadsFeature()->mount($this);
        $this->_stateCollections[ModerationAgentIntegrationConnections::RT_COLLECTION] = ModerationAgentIntegrationConnections::init();
    }

    /**
     * Opens a connection carrying a person, as the master's handshake does.
     *
     * @param string $acceptKey Connection to open
     * @param int $userId Person signed in on it
     */
    public function connect(string $acceptKey, int $userId): void
    {
        $this->_stateCollections[ModerationAgentIntegrationConnections::RT_COLLECTION]->add(
            ModerationAgentIntegrationConnection::create($acceptKey, $userId),
        );
    }
}

final class ModerationAgentIntegrationConnections extends HilosConnections
{
    public const string RT_COLLECTION = 'moderationAgentIntegrationConnections';
    public const string STATE_CLASS = ModerationAgentIntegrationConnection::class;
}

/**
 * Connection row adding no project fields.
 */
final class ModerationAgentIntegrationConnection extends HilosConnection
{
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Serialized runtime row */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No project fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Incoming field changes */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * FS context with a temporary directory of the case's own, the cluster's as the framework requires.
 */
final class ModerationAgentIntegrationFsContext extends FsContext
{
    /** @param string $tmpPath Temporary directory of the case */
    public function __construct(private readonly string $tmpPath)
    {
    }

    public function configure(): void
    {
        $this->setTmpPath($this->tmpPath, DirectoryScope::CLUSTER);
    }
}

/**
 * The moderator with every client it builds kept for the case to answer.
 */
final class ModerationAgentIntegrationAgent extends ModerationAgent
{
    /** @var list<ModerationAgentIntegrationModel> Clients built, in order */
    public array $clients = [];

    /**
     * @param LlmProfile $profile Effective profile the moderator resolved for the next check
     * @return AsyncChatLLMInterface A client the case answers, remembering the profile it was built from
     */
    protected function createChatClient(LlmProfile $profile): AsyncChatLLMInterface
    {
        $client = new ModerationAgentIntegrationModel($profile);
        $this->clients[] = $client;

        return $client;
    }
}

final readonly class ModerationAgentIntegrationRequest
{
    /**
     * @param list<Message> $messages Messages the model was asked
     * @param ChatGenerateOptions $options Options the request went with
     */
    public function __construct(public array $messages, public ChatGenerateOptions $options)
    {
    }
}

/**
 * The provider's async client, answered by the case: nothing comes back until the case says so.
 */
final class ModerationAgentIntegrationModel implements AsyncChatLLMInterface
{
    /** @var list<ModerationAgentIntegrationRequest> Requests started, in order */
    public array $requests = [];

    private bool $busy = false;

    private ?string $result = null;

    private ?LLMException $failure = null;

    /** @param LlmProfile $profile Profile the moderator built this client from */
    public function __construct(public readonly LlmProfile $profile)
    {
    }

    /** @param list<Message> $messages Messages to generate from */
    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        $this->requests[] = new ModerationAgentIntegrationRequest($messages, $options);
        $this->busy = true;
        $this->result = null;
    }

    public function tick(float $currentTimeMs): void
    {
        if ($this->failure === null) {
            return;
        }
        $failure = $this->failure;
        $this->failure = null;
        $this->busy = false;
        throw $failure;
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
        $this->failure = null;
    }

    /** @param string $text What the model answers to the request in flight */
    public function answer(string $text): void
    {
        $this->busy = false;
        $this->result = $text;
    }

    /** @param LLMException $failure What the request in flight ends with on the next pump */
    public function fail(LLMException $failure): void
    {
        $this->failure = $failure;
    }
}
