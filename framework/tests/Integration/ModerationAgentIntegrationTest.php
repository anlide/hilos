<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
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
use Hilos\Files\FileVisibility;
use Hilos\Files\Image\ImagesAgent;
use Hilos\Files\Upload\AbstractUploadTarget;
use Hilos\Files\Upload\DTO\UploadCancelActionDTO;
use Hilos\Files\Upload\DTO\UploadHoldSettleSignalData;
use Hilos\Files\Upload\DTO\UploadInitActionDTO;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\Files\Upload\UploadHoldOutcome;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Files\Upload\UploadSnapshotCollection;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Files\Upload\UploadsAgentDaemon;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsPath;
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
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationLlmConstants;
use Hilos\Moderation\ModerationReason;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosModerationCheck;
use Hilos\Runtime\View\Item\HilosUpload;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
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
    private ?BrowserContext $previousBrowser = null;
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
        $this->previousBrowser = Hilos::$browser;
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
        Hilos::$sr = new ModerationAgentIntegrationSignalRouter();
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
        Hilos::$browser = new ModerationAgentIntegrationBrowser();

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
        Hilos::$browser = $this->previousBrowser;
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

    private UploadsAgent $uploads;

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
        yield 'text with files' => [new ModerationAgentIntegrationRole(
            ModerationAgentIntegrationKinds::NOTE,
            ModerationLlmConstants::PROFILE_TEXT,
            ModerationSettingsCatalog::TEXT_PROVIDER,
            ModerationSettingsCatalog::TEXT_MODEL,
            ModerationSettingsCatalog::TEXT_URL,
            ModerationSettingsCatalog::TEXT_TIMEOUT_SEC,
        )];
        yield 'image copy' => [new ModerationAgentIntegrationRole(
            ModerationAgentIntegrationKinds::PICTURE,
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

    public function testReceivedDocumentBytesAreNeverReadOrSentToVision(): void
    {
        $upload = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document');
        self::assertSame(UploadPhase::COMPLETE, $upload->phase);
        self::assertSame('text/plain', $upload->detectedMimeType);
        // Metadata remains usable without the document bytes on disk.
        FsPath::delete(Hilos::$fs->getTmp()[$upload->tmpIndex]->getPath());
        $check = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'Hello', clientUploadIds: ['document']);
        $this->tick();

        $request = $this->model()->requests[0];
        self::assertSame(ModerationLlmConstants::PROFILE_TEXT, $this->model()->profile->key);
        self::assertSame([], $request->messages[1]->images);
        self::assertStringContainsString('Attachment: name=report.txt, mime=text/plain, size=24 bytes', $request->messages[1]->content);
        self::assertStringNotContainsString('PRIVATE DOCUMENT CONTENT', $request->messages[1]->content);
        self::assertStringContainsString('never grounds for blocking the text', $request->messages[0]->content);
        $this->model()->answer(self::ALLOWED);
        $this->tick();
        $this->assertVerdict($check, true, ModerationReason::OK);
    }

    public function testGenericImageCopyIsJpegButOriginalInputKeepsTheReceivedBytes(): void
    {
        $upload = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'picture');
        $path = Hilos::$fs->getTmp()[$upload->tmpIndex]->getPath();
        $filesBefore = Hilos::$fs->inspection->entries();
        $check = $this->open(ModerationAgentIntegrationKinds::PICTURE, clientUploadId: 'picture', imageCopy: true);
        $this->tick();

        $request = $this->model()->requests[0];
        self::assertCount(1, $request->messages[1]->images);
        self::assertSame('image/jpeg', $request->messages[1]->images[0]->mimeType);
        self::assertSame([512, 256, IMAGETYPE_JPEG], array_slice(
            getimagesizefromstring(base64_decode($request->messages[1]->images[0]->base64, true)), 0, 3));
        self::assertStringContainsString('Picture: name=landscape.png', $request->messages[1]->content);
        self::assertStringNotContainsString('Text:', $request->messages[1]->content);
        self::assertSame(self::png(), FsPath::read($path));
        self::assertSame($filesBefore, Hilos::$fs->inspection->entries());
        $this->model()->answer('{"allow":false,"reason":"nudity"}');
        $this->tick();
        $this->assertVerdict($check, false, 'nudity');
        $this->close($check);

        $this->open(ModerationAgentIntegrationKinds::PICTURE, clientUploadId: 'picture');
        $this->tick();
        $original = $this->model()->requests[1]->messages[1]->images[0];
        self::assertSame('image/png', $original->mimeType);
        self::assertSame(self::png(), base64_decode($original->base64, true));
        self::assertSame($filesBefore, Hilos::$fs->inspection->entries());
    }

    public function testLocalUnreadableDoesNotMakeUnreadableAModelReason(): void
    {
        $upload = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'picture');
        $path = Hilos::$fs->getTmp()[$upload->tmpIndex]->getPath();
        FsPath::write($path, 'corrupt image');
        $filesBefore = Hilos::$fs->inspection->entries();
        $check = $this->open(ModerationAgentIntegrationKinds::PICTURE, clientUploadId: 'picture', imageCopy: true);
        $this->tick();
        self::assertSame([], $this->agent->clients);
        $this->assertVerdict($check, false, ModerationReason::UNREADABLE);
        self::assertSame($filesBefore, Hilos::$fs->inspection->entries());
        $this->close($check);

        FsPath::write($path, self::png());
        $check = $this->open(ModerationAgentIntegrationKinds::PICTURE, clientUploadId: 'picture', imageCopy: true);
        $this->tick();
        $this->model()->answer('{"allow":false,"reason":"unreadable"}');
        $this->tick();
        $this->assertVerdict($check, false, ModerationReason::SERVICE_UNAVAILABLE);
        self::assertSame($filesBefore, Hilos::$fs->inspection->entries());
    }

    public function testGenericAttemptReplacementCancelsTheOldRequest(): void
    {
        $first = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'old', clientUploadIds: ['document']);
        $this->tick();
        $client = $this->model();
        $this->close($first);
        self::assertFalse($client->isBusy());
        $next = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'new');
        $this->tick();
        $client->answer(self::ALLOWED);
        $this->tick();

        $this->assertVerdict($next, true, ModerationReason::OK);
        self::assertNotSame($first->requestId, $next->requestId);
    }

    public function testTheWholeGroupWaitsForOwnerMarksAndUploadSyncWakesTheQueue(): void
    {
        $snapshots = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['document', 'picture']);
        $check = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'Held note',
            clientUploadIds: ['document', 'picture'], uploadSnapshots: $snapshots);
        $this->tick();
        self::assertSame([], $this->agent->clients);
        $this->as(HilosAgentType::HILOS_UPLOADS, static fn () =>
            Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->actions->hold($check->uploadHoldId, $check->deadlineAt));
        $this->uploadChanged('document');
        $this->tick();
        self::assertSame([], $this->agent->clients, 'Partial owner delivery is not permission.');

        $this->admit($check);
        $this->admit($check);
        foreach (['document', 'picture'] as $id) {
            self::assertSame($check->uploadHoldId, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, $id)->uploadHoldId);
            self::assertSame($check->deadlineAt, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, $id)->uploadHoldDeadlineAt);
        }
        $this->uploadChanged('picture');
        $this->tick();
        self::assertCount(1, $this->model()->requests);
        self::assertSame([], $this->model()->requests[0]->messages[1]->images);
        $this->model()->answer(self::ALLOWED);
        $this->tick();
        $this->assertVerdict($check, true, ModerationReason::OK);
    }

    public function testRecreatedBytesNeverAcquireTheOriginalGroupEvenWithTheSameHash(): void
    {
        $snapshots = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['document', 'picture']);
        $old = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'picture')->tmpIndex;
        $replacement = $this->receive('picture', 'changed.png', 'image/png', self::png());
        self::assertNotSame($old, $replacement->tmpIndex);
        $check = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'Original',
            clientUploadIds: ['document', 'picture'], uploadSnapshots: $snapshots);
        $this->admit($check);
        $this->tick();
        self::assertSame([], $this->agent->clients);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->uploadHoldId,
            'The owner validates the whole group before marking its first member.');
        self::assertNull($replacement->uploadHoldId);
        $this->sweepUploads();
        self::assertSame([], $this->agent->clients);
        self::assertSame($snapshots->toArray(), $check->uploadSnapshots->toArray());
    }

    /** @return iterable<string, array{string}> Owner facts that prevent whole-group admission */
    public static function inadmissibleGroups(): iterable
    {
        foreach (['person', 'phase', 'hash', 'active-token', 'stale-upload', 'stale-check'] as $condition) {
            yield $condition => [$condition];
        }
    }

    /** @param string $condition A mismatch in the second member must leave the first member unmarked */
    #[DataProvider('inadmissibleGroups')]
    public function testOwnerAdmissionRequiresEveryOriginalMemberAndFreshSource(string $condition): void
    {
        $snapshots = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['document', 'picture']);
        $check = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'Original',
            clientUploadIds: ['document', 'picture'], uploadSnapshots: $snapshots);
        $key = StateHilosUpload::keyFor(self::ACCEPT_KEY, 'picture');
        if ($condition === 'active-token') {
            $this->as(HilosAgentType::HILOS_UPLOADS, static fn () =>
                Hilos::$rt->hilosUploads[$key]->actions->hold('another-attempt', $check->deadlineAt));
        } elseif ($condition === 'stale-upload') {
            RtStaleness::mark(StateHilosUpload::RT_COLLECTION, [$key], microtime(true));
        } elseif ($condition === 'stale-check') {
            RtStaleness::mark(StateHilosModerationCheck::RT_COLLECTION, [$check->getId()], microtime(true));
        } elseif ($condition === 'person') {
            $rows = RtSnapshot::rows(StateHilosUpload::RT_COLLECTION);
            $rows[$key][StateHilosUpload::userId] = self::USER_ID + 1;
            RtSnapshot::replace(StateHilosUpload::RT_COLLECTION, $rows);
        } else {
            $diff = match ($condition) {
                'phase' => [StateHilosUpload::phase => UploadPhase::UPLOADING->value],
                'hash' => [StateHilosUpload::contentHash => hash('sha256', 'different bytes')],
            };
            RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(StateHilosUpload::RT_COLLECTION, $key, $diff), false);
        }
        try {
            $this->admit($check);
            $this->sweepUploads();
            $this->tick();
            self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->uploadHoldId);
            self::assertSame([], $this->agent->clients);
            if ($condition === 'active-token') {
                self::assertSame('another-attempt', Hilos::$rt->hilosUploads[$key]->uploadHoldId);
            } else {
                self::assertNull(Hilos::$rt->hilosUploads[$key]->uploadHoldId);
            }
        } finally {
            RtStaleness::reset();
        }
    }

    public function testRecoveryWalkIsBoundedAndContinuesWithoutASecondRequestQueue(): void
    {
        $runtime = Hilos::$rt;
        self::assertInstanceOf(ModerationAgentIntegrationRtContext::class, $runtime);
        $checks = [];
        for ($i = 0; $i < 20; $i++) {
            $acceptKey = 'recovery-' . $i;
            $runtime->connect($acceptKey, self::USER_ID);
            $this->as(HilosAgentType::HILOS_UPLOADS, static function () use ($acceptKey): void {
                $tmp = Hilos::$fs->getTmp();
                $index = $tmp->create();
                $tmp[$index]->append('received bytes');
                $upload = Hilos::$rt->hilosUploads->actions->open(
                    $acceptKey, 'file', 'moderation_test', self::USER_ID, 'file.txt', 'text/plain', 14, $index);
                $upload->actions->inspect(hash('sha256', 'received bytes'), 'text/plain');
                $upload->actions->complete();
            });
            $checks[] = $this->as(HilosAgentType::HILOS_USERS_LIBRARY, static fn () =>
                Hilos::$rt->hilosModerationChecks->actions->open($acceptKey, self::USER_ID, ModerationAgentIntegrationKinds::NOTE,
                    text: 'Original', clientUploadIds: ['file'],
                    uploadSnapshots: Hilos::$rt->hilosUploads->snapshotForConnection($acceptKey, ['file'])));
        }
        $this->sweepUploads();
        $marked = array_filter($checks, static fn (HilosModerationCheck $check): bool =>
            Hilos::$rt->hilosUploads->find($check->acceptKey, 'file')->uploadHoldId !== null);
        self::assertNotEmpty($marked);
        self::assertLessThan(count($checks), count($marked), 'One pass must leave work for another tick.');
        // A cursor may already have fetched this key. Replacing its check between ticks must
        // resolve the new request, rather than grant the removed request's token.
        foreach ($checks as $i => $check) {
            if (Hilos::$rt->hilosUploads->find($check->acceptKey, 'file')->uploadHoldId !== null) {
                continue;
            }
            $this->as(HilosAgentType::HILOS_USERS_LIBRARY, static fn () => $check->actions->forget());
            $checks[$i] = $this->as(HilosAgentType::HILOS_USERS_LIBRARY, static fn () =>
                Hilos::$rt->hilosModerationChecks->actions->open($check->acceptKey, self::USER_ID, ModerationAgentIntegrationKinds::NOTE,
                    text: 'Replacement request', clientUploadIds: ['file'], uploadSnapshots: $check->uploadSnapshots));
            self::assertNotSame($check->uploadHoldId, $checks[$i]->uploadHoldId);
            break;
        }
        for ($i = 0; $i < count($checks); $i++) {
            $this->as(HilosAgentType::HILOS_UPLOADS, fn () => $this->uploads->onTick());
        }
        foreach ($checks as $check) {
            self::assertSame($check->uploadHoldId, Hilos::$rt->hilosUploads->find($check->acceptKey, 'file')->uploadHoldId);
        }
    }

    public function testAnExpiredPendingRequestCannotAcquireMarksOrStartAModelAfterRecovery(): void
    {
        $snapshots = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['document']);
        $check = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'Original',
            clientUploadIds: ['document'], uploadSnapshots: $snapshots);
        // Restore the request as it arrives after a source wait has used up its fixed deadline.
        $row = $check->toArray();
        $row[StateHilosModerationCheck::deadlineAt] = TimeHelper::nowMs() - 1;
        $row[StateHilosModerationCheck::startedAt] = $row[StateHilosModerationCheck::deadlineAt] - 60000;
        RtSnapshot::replace(StateHilosModerationCheck::RT_COLLECTION, [$check->getId() => $row]);
        $expired = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationAgentIntegrationKinds::NOTE);
        $this->admit($expired);
        $this->sweepUploads();
        $this->tick();
        self::assertSame([], $this->agent->clients);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->uploadHoldId);
        self::assertSame($row[StateHilosModerationCheck::deadlineAt], $expired->deadlineAt);
        $this->close($expired);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationAgentIntegrationKinds::NOTE));
    }

    public function testAnUnconfirmedOlderGroupDoesNotBlockAnEligibleCheck(): void
    {
        $snapshots = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['document']);
        $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'Still waiting',
            clientUploadIds: ['document'], uploadSnapshots: $snapshots);
        $ready = $this->open(ModerationKindKey::PROFILE_NAME, text: 'Ready');
        $this->tick();
        self::assertStringContainsString('Ready', $this->model()->requests[0]->messages[1]->content);
        $this->model()->answer(self::ALLOWED);
        $this->tick();
        $this->assertVerdict($ready, true, ModerationReason::OK);
    }

    public function testOwnerHoldBlocksInitCancelAndCrossTargetWithoutAConnectionPolicy(): void
    {
        $snapshots = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['picture']);
        $check = $this->open(ModerationAgentIntegrationKinds::PICTURE, clientUploadId: 'picture', uploadSnapshots: $snapshots);
        $this->admit($check);
        $before = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'picture')->toArray();
        foreach ([null, 'moderation_test', ProfilePhotoUploadTarget::NAME] as $target) {
            try {
                $this->as(HilosAgentType::HILOS_UPLOADS, fn () => $this->uploads->onAgentAction(
                    self::ACCEPT_KEY, $target === null ? HilosSignalConstants::HILOS_UPLOAD_CANCEL : HilosSignalConstants::HILOS_UPLOAD_INIT,
                    $target === null ? new UploadCancelActionDTO('picture')
                        : new UploadInitActionDTO($target, 'picture', 'replacement.png', 'image/png', 3),
                ));
                self::fail('The owner must refuse a browser mutation of held bytes.');
            } catch (ValidationException $refusal) {
                self::assertSame('This file is being checked; wait for the result', $refusal->getMessage());
            }
            self::assertSame($before, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'picture')->toArray());
        }
        $this->as(HilosAgentType::HILOS_UPLOADS, fn () => $this->uploads->onSignalFrameBinary(
            new WebSocketFrameBinarySignalDTO(self::ACCEPT_KEY, chr(7) . 'picture' . 'new bytes'), SignalSource::WEBSOCKET, '',
        ));
        self::assertSame($before, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'picture')->toArray());
    }

    public function testDeletionBetweenStagesKeepsTheWholeGroupAndModeratorRestartRechecksIt(): void
    {
        $snapshots = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['document', 'picture']);
        $first = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'Original',
            clientUploadIds: ['document', 'picture'], uploadSnapshots: $snapshots);
        $this->admit($first);
        $this->close($first);
        $next = $this->open(ModerationAgentIntegrationKinds::PICTURE, clientUploadId: 'picture', imageCopy: true,
            uploadSnapshots: $snapshots, uploadHoldId: $first->uploadHoldId, deadlineAt: $first->deadlineAt);
        $this->admit($next);
        self::assertSame($first->deadlineAt, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->uploadHoldDeadlineAt);
        self::assertNotSame($first->requestId, $next->requestId);
        $this->tick();
        $this->agent->onStop();
        $this->agent = new ModerationAgentIntegrationAgent();
        $this->tick();
        self::assertSame('image/jpeg', $this->model()->requests[0]->messages[1]->images[0]->mimeType);

        // Losing an earlier stage's member must also invalidate this image's allow.
        $this->as(HilosAgentType::HILOS_UPLOADS, static fn () =>
            Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->actions->releaseHold($first->uploadHoldId));
        $this->model()->answer(self::ALLOWED);
        $this->tick();
        $this->assertVerdict($next, false, ModerationReason::SERVICE_UNAVAILABLE);
    }

    public function testReleaseIsIdempotentOldRequestsCannotReopenItAndANewTokenSurvivesALateRelease(): void
    {
        $snapshots = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['document']);
        $first = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'First',
            clientUploadIds: ['document'], uploadSnapshots: $snapshots);
        $this->admit($first);
        $this->settle($first->uploadHoldId, UploadHoldOutcome::APPROVED);
        self::assertTrue(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->uploadHoldApproved);
        $this->settle($first->uploadHoldId, UploadHoldOutcome::RELEASED);
        $this->settle($first->uploadHoldId, UploadHoldOutcome::RELEASED);
        $this->admit($first);
        $this->sweepUploads();
        $this->settle($first->uploadHoldId, UploadHoldOutcome::APPROVED);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->uploadHoldDeadlineAt);
        self::assertFalse(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->uploadHoldApproved);
        $this->close($first);
        $next = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'Next',
            clientUploadIds: ['document'], uploadSnapshots: $snapshots);
        $this->admit($next);
        $this->settle($first->uploadHoldId, UploadHoldOutcome::RELEASED);
        self::assertSame($next->uploadHoldId, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->uploadHoldId);
        self::assertSame($next->deadlineAt, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->uploadHoldDeadlineAt);
    }

    public function testRecoveryAdmitsExistingChecksButAStaleSourceNeverGrantsPermission(): void
    {
        $snapshots = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['document']);
        $check = $this->open(ModerationAgentIntegrationKinds::NOTE, text: 'Recovered',
            clientUploadIds: ['document'], uploadSnapshots: $snapshots);
        $this->sweepUploads();
        self::assertSame($check->uploadHoldId, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->uploadHoldId);
        $this->tick();
        RtStaleness::mark(StateHilosUpload::RT_COLLECTION,
            [Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'document')->getId()], microtime(true));
        try {
            $this->model()->answer(self::ALLOWED);
            $this->tick();
            $this->assertVerdict($check, false, ModerationReason::SERVICE_UNAVAILABLE);
        } finally {
            RtStaleness::reset();
        }
    }

    public function testExpiryReleasesMarksAndRestartCannotPinAReplacementUnderTheOldSnapshot(): void
    {
        $snapshots = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['picture']);
        $check = $this->open(ModerationAgentIntegrationKinds::PICTURE, clientUploadId: 'picture', uploadSnapshots: $snapshots);
        $this->admit($check);
        $this->tick();
        $this->settle($check->uploadHoldId, UploadHoldOutcome::APPROVED);
        $key = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'picture')->getId();
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(StateHilosUpload::RT_COLLECTION, $key,
            [StateHilosUpload::uploadHoldDeadlineAt => TimeHelper::nowMs() - 1]), false);
        $this->sweepUploads();
        self::assertSame($check->uploadHoldId, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'picture')->uploadHoldId);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'picture')->uploadHoldDeadlineAt);
        self::assertFalse(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'picture')->uploadHoldApproved);
        $this->model()->answer(self::ALLOWED);
        $this->tick();
        $this->assertVerdict($check, false, ModerationReason::SERVICE_UNAVAILABLE);

        $this->as(HilosAgentType::HILOS_UPLOADS, fn () => $this->uploads->onStart());
        $replacement = $this->receive('picture', 'replacement.png', 'image/png', self::png());
        $this->admit($check);
        $this->sweepUploads();
        self::assertNull($replacement->uploadHoldId);
    }

    /** The person whose content is checked, on an open connection, with a complete photo upload. */
    protected function setUpInTransaction(): void
    {
        Database::sqlRun('INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, ?)', [self::USER_ID, self::PERSON_NAME]);
        $runtime = Hilos::$rt;
        self::assertInstanceOf(ModerationAgentIntegrationRtContext::class, $runtime);
        $runtime->connect(self::ACCEPT_KEY, self::USER_ID);

        $this->uploads = new UploadsAgent();
        $this->as(HilosAgentType::HILOS_UPLOADS, fn () => $this->uploads->onStart());
        $this->receive('document', 'report.txt', 'application/octet-stream', 'PRIVATE DOCUMENT CONTENT');
        $this->receive('picture', 'landscape.png', 'image/png', self::png());

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
     * @param string $id Upload id
     * @param string $name File name
     * @param string $mime Declared type
     * @param string $bytes Signed binary chunk
     * @return HilosUpload Real complete upload after content inspection
     */
    private function receive(string $id, string $name, string $mime, string $bytes): HilosUpload
    {
        $this->as(HilosAgentType::HILOS_UPLOADS, function () use ($id, $name, $mime, $bytes): void {
            $this->uploads->onAgentAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_UPLOAD_INIT,
                new UploadInitActionDTO('moderation_test', $id, $name, $mime, strlen($bytes)));
            $this->uploads->onSignalFrameBinary(
                new WebSocketFrameBinarySignalDTO(self::ACCEPT_KEY, chr(strlen($id)) . $id . $bytes), SignalSource::WEBSOCKET, '',
            );
        });
        $upload = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, $id);
        self::assertNotNull($upload);
        self::assertSame(UploadPhase::COMPLETE, $upload->phase);

        return $upload;
    }

    /** @return string A real landscape PNG larger than the requested copy */
    private static function png(): string
    {
        $image = imagecreatetruecolor(1024, 512);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    /**
     * Opens a check of the role's kind as the people library does, and hands the moderator its sync.
     */
    private function openCheck(ModerationAgentIntegrationRole $role): HilosModerationCheck
    {
        $input = Hilos::moderationKinds()[$role->kind]->input;

        return $this->open(
            $role->kind,
            text: $input === ModerationInput::IMAGE ? null : self::NAME,
            clientUploadId: $input === ModerationInput::IMAGE
                ? ($role->kind === ModerationAgentIntegrationKinds::PICTURE ? 'picture' : self::UPLOAD_ID) : null,
            clientUploadIds: $input === ModerationInput::TEXT_WITH_FILES ? ['document'] : [],
            imageCopy: $role->kind === ModerationAgentIntegrationKinds::PICTURE,
        );
    }

    /**
     * @param string $kind Declared generic or framework kind
     * @param ?string $text Text input
     * @param ?string $clientUploadId Image upload
     * @param list<string> $clientUploadIds Ordered attachments
     * @param bool $imageCopy Whether to draw a JPEG
     * @param ?UploadSnapshotCollection $uploadSnapshots Original complete group
     * @param ?string $uploadHoldId Shared first stage token
     * @param ?int $deadlineAt Original fixed deadline
     * @return HilosModerationCheck Admitted attempt under the initiator's claim
     */
    private function open(string $kind, ?string $text = null, ?string $clientUploadId = null,
        array $clientUploadIds = [], bool $imageCopy = false, ?UploadSnapshotCollection $uploadSnapshots = null,
        ?string $uploadHoldId = null, ?int $deadlineAt = null): HilosModerationCheck
    {
        $check = $this->as(HilosAgentType::HILOS_USERS_LIBRARY, static fn (): HilosModerationCheck =>
            Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, self::USER_ID, $kind,
                text: $text, clientUploadId: $clientUploadId, clientUploadIds: $clientUploadIds, imageCopy: $imageCopy,
                uploadSnapshots: $uploadSnapshots, uploadHoldId: $uploadHoldId, deadlineAt: $deadlineAt));
        $sync = new RtSyncCreatedSignalData(StateHilosModerationCheck::RT_COLLECTION, $check->getId(), $check->toArray());
        $this->as($this->agent->getId(), fn () => $this->agent->onSignalRtSyncCreated($sync, 'rt', 'created'));

        return $check;
    }

    /** @param HilosModerationCheck $check The real created request delivered to its uploads owner */
    private function admit(HilosModerationCheck $check): void
    {
        $sync = new RtSyncCreatedSignalData(StateHilosModerationCheck::RT_COLLECTION, $check->getId(), $check->toArray());
        $this->as(HilosAgentType::HILOS_UPLOADS, fn () => $this->uploads->onSignalRtSyncCreated($sync, 'rt', 'created'));
    }

    /** Runs the existing bounded recovery and expiry pass without waiting for its cadence. */
    private function sweepUploads(): void
    {
        new ReflectionProperty(UploadsAgent::class, 'lastSweepAt')->setValue($this->uploads, 0.0);
        $this->as(HilosAgentType::HILOS_UPLOADS, fn () => $this->uploads->onTick());
    }

    /** @param string $id Upload whose applied sync wakes the moderator */
    private function uploadChanged(string $id): void
    {
        $upload = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, $id);
        $sync = new RtSyncUpdatedSignalData(StateHilosUpload::RT_COLLECTION, $upload->getId(), $upload->toArray());
        $this->agent->onSignalRtSyncUpdated($sync, 'rt', 'updated');
    }

    /**
     * @param string $token Attempt to settle
     * @param UploadHoldOutcome $outcome Final consumer decision
     */
    private function settle(string $token, UploadHoldOutcome $outcome): void
    {
        $payload = new UploadHoldSettleSignalData(self::ACCEPT_KEY, $token, $outcome);
        $signal = Hilos::$sr->createAgentSignalPayloadDTO(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE,
            new AgentSignalData(new SignalData($payload->toArray())));
        self::assertInstanceOf(UploadHoldSettleSignalData::class, $signal->data);
        $this->as(HilosAgentType::HILOS_UPLOADS, fn () =>
            $this->uploads->onSignalAgent($signal, HilosAgentType::HILOS_USERS_LIBRARY, HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE));
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
            if ($kind->key === ModerationAgentIntegrationKinds::PICTURE) {
                self::assertSame($context . "\n\nPicture: name=landscape.png", $request->messages[1]->content);
                self::assertCount(1, $request->messages[1]->images);
                self::assertSame('image/jpeg', $request->messages[1]->images[0]->mimeType);
                self::assertSame([512, 256, IMAGETYPE_JPEG], array_slice(
                    getimagesizefromstring(base64_decode($request->messages[1]->images[0]->base64, true)), 0, 3));

                return;
            }
            self::assertSame($context . "\n\nPicture: name=photo.png", $request->messages[1]->content);
            self::assertEquals([new MessageImage(self::PHOTO_TYPE, base64_encode(self::PHOTO_BYTES))], $request->messages[1]->images);

            return;
        }
        if ($kind->input === ModerationInput::TEXT_WITH_FILES) {
            self::assertSame($context . "\n\nText:\n" . self::NAME
                . "\n\nAttachment: name=report.txt, mime=text/plain, size=24 bytes", $request->messages[1]->content);
            self::assertSame([], $request->messages[1]->images);

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
    public const array AGENTS = [
        HilosAgentType::HILOS_IMAGES => [AgentRegistryKey::WORKER => ImagesAgent::class],
        HilosAgentType::HILOS_UPLOADS => [AgentRegistryKey::WORKER => UploadsAgent::class, AgentRegistryKey::DAEMON => UploadsAgentDaemon::class],
    ];
    public const array UPLOAD_TARGETS = ['moderation_test' => ModerationIntegrationUploadTarget::class];
    protected const array FEATURES = [HilosFeature::MODERATION, HilosFeature::PROFILE_PHOTO, HilosFeature::UPLOADS];
    protected const string MODERATION_KIND_DIRECTORY = ModerationAgentIntegrationKinds::class;
}

/** Uses the fixture's declarative topology for real payload hydration. */
final class ModerationAgentIntegrationSignalRouter extends SignalRouter
{
    /** @return class-string<Hilos> Fixture topology */
    protected function hilosClass(): string
    {
        return ModerationAgentIntegrationHilos::class;
    }
}

/** Generic project kinds beside the framework's name and photo checks. */
final class ModerationAgentIntegrationKinds extends ModerationKindDirectory
{
    public const string NOTE = 'integration_note';
    public const string PICTURE = 'integration_picture';

    /** @return list<ModerationKind> Two generic project inputs and the unchanged framework kinds */
    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            new ModerationKind(self::NOTE, 'Note with files', ModerationInput::TEXT_WITH_FILES,
                ['spam'], ['Allow notes.'], HilosAgentType::HILOS_USERS_LIBRARY),
            new ModerationKind(self::PICTURE, 'Picture', ModerationInput::IMAGE,
                ['nudity'], ['Allow ordinary pictures.'], HilosAgentType::HILOS_USERS_LIBRARY),
        ];
    }
}

/** Resolves the same person as the real connection row used by check admission. */
final class ModerationAgentIntegrationBrowser extends BrowserContext
{
    /**
     * @param string $acceptKey Upload's connection
     * @return ConnectionIdentity Current person on that connection
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(Hilos::$rt->connectionsSource()?->get($acceptKey)?->userId);
    }
}

/** Accepts and sniffs the generic fixtures through the real signed-chunk upload pipeline. */
final class ModerationIntegrationUploadTarget extends AbstractUploadTarget
{
    /** @return int Ample room for the PNG fixture */
    public function maxBytes(): int
    {
        return 1048576;
    }

    /** @return bool This fixture target also accepts anonymous uploads */
    public function requiresSignIn(): bool
    {
        return false;
    }

    /** @return FileVisibility The ordinary authenticated file policy */
    public function visibility(): FileVisibility
    {
        return FileVisibility::AUTHENTICATED;
    }

    /** @return bool Record the actual document or picture type */
    public function sniffsContent(): bool
    {
        return true;
    }
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
 *
 * @property-read FsDirectory $inspection Marker-aware listing of the temporary directory
 */
final class ModerationAgentIntegrationFsContext extends FsContext
{
    /** @param string $tmpPath Temporary directory of the case */
    public function __construct(private readonly string $tmpPath)
    {
    }

    /** Registers tmp and a marker-aware listing of the same cluster directory. */
    public function configure(): void
    {
        $this->setTmpPath($this->tmpPath, DirectoryScope::CLUSTER);
        $this->registerDirectory('inspection', $this->tmpPath, DirectoryScope::CLUSTER);
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
