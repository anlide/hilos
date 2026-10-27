<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The row of a moderation check and the door that opens it (HIL-1625).
 *
 * The row survives the trip between workers with either input left empty, and is keyed by the
 * connection and the kind. The door takes the verdict recipient from the kind and the deadline
 * from the setting, draws a fresh request id every time, refuses a kind, an input or a slot that
 * does not fit, and lets each initiator bring into being and end the checks of its own set alone.
 */
final class HilosModerationCheckTest extends TestCase
{
    public const string INITIATOR_A = 'unit_moderation_initiator_a';
    public const string INITIATOR_B = 'unit_moderation_initiator_b';
    private const string ACCEPT_KEY = 'ak-1';
    private const string OTHER_ACCEPT_KEY = 'ak-2';
    private const int USER_ID = 7;
    private const string TEXT = 'A display name';
    private const string UPLOAD = 'upload-1';
    private const int TIMEOUT_SEC = 30;
    private const int RAISED_TIMEOUT_SEC = 120;
    private const string SESSION_HASH = 'accepted-session-hash';
    private const string REQUEST_ID = '0123456789abcdef0123456789abcdef';
    private const string SESSION_HOLDER = 'unit_name_change_holder';

    /** @var string App facade class bound before the test, restored afterwards */
    private string $boundAppClass = Hilos::class;

    private ?RtContext $previousRuntime = null;

    private ?SettingsAccessor $previousSetting = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boundAppClass = Hilos::appClass();
        $this->previousRuntime = Hilos::$rt;
        $this->previousSetting = Hilos::$setting;
        self::bindAppClass(ModerationCheckTestHilos::class);
        ModerationCheckTestSettings::$timeoutSec = self::TIMEOUT_SEC;
        Hilos::$setting = new ModerationCheckTestSettings();
        Hilos::$rt = new ModerationCheckRtContext();
        Hilos::$rt->configure();
        // Both are done by facade init() in a running process: a store that never learned its name
        // announces nothing, and without the subscriber the view keeps the wrapper of a row that left.
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());

        foreach ([self::INITIATOR_A, self::INITIATOR_B] as $initiator) {
            RtTruthSourceRegistry::register(
                HilosModerationCheck::RT_COLLECTION,
                TruthSourceKeys::set($initiator),
                $initiator,
                TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove),
            );
        }
        ExecutionContext::setCurrentAgentId(self::INITIATOR_A);
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(self::INITIATOR_A);
        RtTruthSourceRegistry::unregisterAgent(self::INITIATOR_B);
        RtTruthSourceRegistry::unregisterAgent(self::SESSION_HOLDER);
        RtTruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_USERS_LIBRARY);
        SourceInterestRegistry::readsWhatItMounts();
        RtStaleness::reset();
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRuntime;
        Hilos::$setting = $this->previousSetting;
        self::bindAppClass($this->boundAppClass);

        parent::tearDown();
    }

    public function testRoundTripsATextCheckWithNoUpload(): void
    {
        $check = HilosModerationCheck::create(
            self::ACCEPT_KEY,
            self::USER_ID,
            ModerationCheckTestDirectory::A_NOTE,
            'request-1',
            self::INITIATOR_A,
            self::TEXT,
            null,
            1000,
            91000,
        );
        $restored = HilosModerationCheck::fromRow($check->toArray());

        $this->assertSame($check->toArray(), $restored->toArray());
        $this->assertSame(self::TEXT, $restored->text);
        $this->assertNull($restored->clientUploadId);
        $this->assertNull($restored->sessionTokenHash);
        $this->assertArrayHasKey(HilosModerationCheck::sessionTokenHash, $restored->toArray());
    }

    public function testRoundTripsAnImageCheckWithNoText(): void
    {
        $check = HilosModerationCheck::create(
            self::ACCEPT_KEY,
            self::USER_ID,
            ModerationCheckTestDirectory::B_PHOTO,
            'request-1',
            self::INITIATOR_B,
            null,
            self::UPLOAD,
            1000,
            91000,
        );
        $restored = HilosModerationCheck::fromRow($check->toArray());

        $this->assertSame($check->toArray(), $restored->toArray());
        $this->assertNull($restored->text);
        $this->assertSame(self::UPLOAD, $restored->clientUploadId);
    }

    public function testRefusesASyncRowThatLostItsRequestId(): void
    {
        $row = $this->textRow();
        unset($row[HilosModerationCheck::requestId]);

        $this->expectException(InvalidFormatException::class);
        HilosModerationCheck::fromRow($row);
    }

    public function testRestoresALegacyRowWithoutASessionHashAsNull(): void
    {
        $row = $this->textRow();
        unset($row[HilosModerationCheck::sessionTokenHash]);

        $restored = HilosModerationCheck::fromRow($row);
        self::assertNull($restored->sessionTokenHash);
        self::assertSame([...$row, HilosModerationCheck::sessionTokenHash => null], $restored->toArray());
    }

    public function testRoundTripsANewSessionHash(): void
    {
        $row = $this->textRow();
        $row[HilosModerationCheck::sessionTokenHash] = self::SESSION_HASH;
        $restored = HilosModerationCheck::fromRow($row);

        self::assertSame(self::SESSION_HASH, $restored->sessionTokenHash);
        self::assertSame($row, $restored->toArray());
    }

    public function testRefusesAMistypedSessionHash(): void
    {
        $row = $this->textRow();
        $row[HilosModerationCheck::sessionTokenHash] = false;

        $this->expectException(InvalidFormatException::class);
        HilosModerationCheck::fromRow($row);
    }

    public function testRefusesASyncRowCarryingTheTextAsAnotherType(): void
    {
        $row = $this->textRow();
        $row[HilosModerationCheck::text] = 42;

        $this->expectException(InvalidFormatException::class);
        HilosModerationCheck::fromRow($row);
    }

    public function testKeysTheRowByTheConnectionAndTheKind(): void
    {
        $opened = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY,
            self::USER_ID,
            ModerationCheckTestDirectory::A_NOTE,
            self::TEXT,
        );

        $this->assertSame(self::ACCEPT_KEY . '|' . ModerationCheckTestDirectory::A_NOTE, $opened->getId());
        $this->assertSame(
            $opened->requestId,
            Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::A_NOTE)?->requestId,
        );
        $this->assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::B_PHOTO));
        $this->assertNull(Hilos::$rt->hilosModerationChecks->find(self::OTHER_ACCEPT_KEY, ModerationCheckTestDirectory::A_NOTE));
    }

    public function testOpeningTakesTheRecipientFromTheKindAndTheDeadlineFromTheSetting(): void
    {
        $before = TimeHelper::nowMs();
        $check = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY,
            self::USER_ID,
            ModerationCheckTestDirectory::A_NOTE,
            self::TEXT,
        );
        $after = TimeHelper::nowMs();

        $this->assertSame(self::ACCEPT_KEY, $check->acceptKey);
        $this->assertSame(self::USER_ID, $check->userId);
        $this->assertSame(ModerationCheckTestDirectory::A_NOTE, $check->kind);
        $this->assertSame(self::INITIATOR_A, $check->verdictTo);
        $this->assertSame(self::TEXT, $check->text);
        $this->assertNull($check->clientUploadId);
        $this->assertNull($check->sessionTokenHash);
        $this->assertGreaterThanOrEqual($before, $check->startedAt);
        $this->assertLessThanOrEqual($after, $check->startedAt);
        $this->assertSame($check->startedAt + 30000, $check->deadlineAt);
    }

    /**
     * A raised timeout reaches the next attempt, and the deadline of an open one stays as it was.
     */
    public function testARaisedTimeoutReachesOnlyTheChecksOpenedAfterIt(): void
    {
        $first = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY,
            self::USER_ID,
            ModerationCheckTestDirectory::A_NOTE,
            self::TEXT,
        );
        ModerationCheckTestSettings::$timeoutSec = self::RAISED_TIMEOUT_SEC;
        $second = Hilos::$rt->hilosModerationChecks->actions->open(
            self::OTHER_ACCEPT_KEY,
            self::USER_ID,
            ModerationCheckTestDirectory::A_NOTE,
            self::TEXT,
        );

        $this->assertSame($second->startedAt + 120000, $second->deadlineAt);
        $this->assertSame(
            $first->startedAt + 30000,
            Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::A_NOTE)?->deadlineAt,
        );
    }

    public function testEveryOpeningDrawsAFreshRequestId(): void
    {
        $checks = Hilos::$rt->hilosModerationChecks;
        $first = $checks->actions->open(self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_NOTE, self::TEXT)->requestId;
        $checks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::A_NOTE)?->actions->forget();
        $second = $checks->actions->open(self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_NOTE, self::TEXT)->requestId;

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $second);
        $this->assertNotSame($first, $second);
    }

    public function testRefusesAKindTheProjectDoesNotDeclare(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown moderation kind: project_unknown');
        Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, self::USER_ID, 'project_unknown', self::TEXT);
    }

    /**
     * @param string $kind Kind the check is opened for
     * @param ?string $text Text handed over
     * @param ?string $clientUploadId Upload handed over
     */
    #[DataProvider('mismatchedInputs')]
    public function testRefusesAnInputTheKindDoesNotLookAt(string $kind, ?string $text, ?string $clientUploadId): void
    {
        ExecutionContext::setCurrentAgentId(self::INITIATOR_B);

        try {
            Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, self::USER_ID, $kind, $text, $clientUploadId);
            $this->fail('An input the kind does not look at was accepted');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString("Moderation kind {$kind} looks at", $refusal->getMessage());
        }
        $this->assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $kind));
    }

    /** @return iterable<string, array{string, ?string, ?string}> Kind and input that do not fit together */
    public static function mismatchedInputs(): iterable
    {
        yield 'text kind without text' => [ModerationCheckTestDirectory::B_NOTE, null, null];
        yield 'text kind with an upload' => [ModerationCheckTestDirectory::B_NOTE, self::TEXT, self::UPLOAD];
        yield 'text kind with an upload alone' => [ModerationCheckTestDirectory::B_NOTE, null, self::UPLOAD];
        yield 'image kind with text' => [ModerationCheckTestDirectory::B_PHOTO, self::TEXT, self::UPLOAD];
        yield 'image kind without an upload' => [ModerationCheckTestDirectory::B_PHOTO, null, null];
        yield 'image kind with an empty upload id' => [ModerationCheckTestDirectory::B_PHOTO, null, ''];
    }

    public function testRefusesTextWithFilesAsNotSupportedYet(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A check of text with attached files is not supported yet.');
        Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY,
            self::USER_ID,
            ModerationCheckTestDirectory::A_ATTACHMENTS,
            self::TEXT,
        );
    }

    public function testRefusesASecondCheckOfTheSameKindOnOneConnection(): void
    {
        $first = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY,
            self::USER_ID,
            ModerationCheckTestDirectory::A_NOTE,
            self::TEXT,
        );

        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY,
                self::USER_ID,
                ModerationCheckTestDirectory::A_NOTE,
                'Another name',
            );
            $this->fail('A second check of one kind on one connection was opened');
        } catch (DuplicateValueException) {
        }

        $standing = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::A_NOTE);
        $this->assertSame($first->requestId, $standing?->requestId);
        $this->assertSame(self::TEXT, $standing?->text);
    }

    public function testRefusesAPersonIdThatIsNotPositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A moderation check needs a positive person id, got 0');
        Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, 0, ModerationCheckTestDirectory::A_NOTE, self::TEXT);
    }

    public function testRefusesToOpenACheckInAProjectWithoutModeration(): void
    {
        self::bindAppClass(ModerationCheckOffTestHilos::class);

        $this->expectException(InvalidStateException::class);
        $this->expectExceptionMessage('HilosFeature::MODERATION');
        Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY,
            self::USER_ID,
            ModerationCheckTestDirectory::A_NOTE,
            self::TEXT,
        );
    }

    /**
     * The recipient comes from the kind, so an initiator cannot open a check another one would receive.
     */
    public function testAnInitiatorIsRefusedACheckBornInAnotherSet(): void
    {
        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY,
                self::USER_ID,
                ModerationCheckTestDirectory::B_PHOTO,
                null,
                self::UPLOAD,
            );
            $this->fail('An initiator opened a check of another set');
        } catch (RtTruthSourceWriteNotAllowedException $refusal) {
            $this->assertStringContainsString("it holds set '" . self::INITIATOR_A . "'", $refusal->getMessage());
        }

        $this->assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::B_PHOTO));
    }

    public function testTwoInitiatorsKeepTheirChecksApart(): void
    {
        $checks = Hilos::$rt->hilosModerationChecks;
        $checks->actions->open(self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_NOTE, self::TEXT);
        ExecutionContext::setCurrentAgentId(self::INITIATOR_B);
        $checks->actions->open(self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::B_PHOTO, null, self::UPLOAD);

        ExecutionContext::setCurrentAgentId(self::INITIATOR_A);
        try {
            $checks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::B_PHOTO)?->actions->forget();
            $this->fail("An initiator ended another initiator's check");
        } catch (RtTruthSourceWriteNotAllowedException) {
        }
        $checks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::A_NOTE)?->actions->forget();

        $this->assertNull($checks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::A_NOTE));
        $this->assertSame(self::INITIATOR_B, $checks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::B_PHOTO)?->verdictTo);

        ExecutionContext::setCurrentAgentId(self::INITIATOR_B);
        $checks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::B_PHOTO)?->actions->forget();

        $this->assertNull($checks->find(self::ACCEPT_KEY, ModerationCheckTestDirectory::B_PHOTO));
    }

    public function testAnAcceptedNameKeepsItsCorrelationAndDeadlineWithoutAnOriginConnection(): void
    {
        $this->mountNameAttempts();
        $startedAt = TimeHelper::nowMs() - 1000;
        $deadlineAt = $startedAt + 90000;
        $this->acceptNameAttempt(startedAt: $startedAt, deadlineAt: $deadlineAt);
        // No connection collection exists here. A closed origin cannot cancel an accepted attempt.
        Hilos::$setting = null;

        $check = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY,
            self::USER_ID,
            ModerationKindKey::PROFILE_NAME,
            self::TEXT,
            sessionTokenHash: self::SESSION_HASH,
            requestId: self::REQUEST_ID,
            deadlineAt: $deadlineAt,
        );

        self::assertSame(self::SESSION_HASH, $check->sessionTokenHash);
        self::assertSame(self::REQUEST_ID, $check->requestId);
        self::assertSame($deadlineAt, $check->deadlineAt);
        self::assertSame($startedAt, $check->startedAt);
        self::assertSame(HilosAgentType::HILOS_USERS_LIBRARY, $check->verdictTo);
        self::assertSame(self::ACCEPT_KEY . '|' . ModerationKindKey::PROFILE_NAME, $check->getId());
        self::assertSame($check->toArray(), HilosModerationCheck::fromRow($check->toArray())->toArray());
        self::assertSame(self::REQUEST_ID, Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->requestId);
    }

    public function testLegacyFrameworkNameNeedsNoAuthState(): void
    {
        RtTruthSourceRegistry::register(
            HilosModerationCheck::RT_COLLECTION,
            TruthSourceKeys::set(HilosAgentType::HILOS_USERS_LIBRARY),
            HilosAgentType::HILOS_USERS_LIBRARY,
        );
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_USERS_LIBRARY);
        self::assertFalse(isset(Hilos::$rt->hilosProfileNameChanges));

        $check = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationKindKey::PROFILE_NAME, self::TEXT,
        );

        self::assertNull($check->sessionTokenHash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $check->requestId);
        self::assertSame($check->startedAt + 30000, $check->deadlineAt);
    }

    public function testSessionBoundNameCannotOpenWithoutAuth(): void
    {
        $queueBefore = Hilos::$rt->hilosModerationChecks->toArray();
        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY, self::USER_ID, ModerationKindKey::PROFILE_NAME, self::TEXT,
                sessionTokenHash: self::SESSION_HASH, requestId: self::REQUEST_ID, deadlineAt: PHP_INT_MAX,
            );
            self::fail('A session-bound name check was opened without AUTH');
        } catch (InvalidArgumentException) {
        }
        self::assertSame($queueBefore, Hilos::$rt->hilosModerationChecks->toArray());
    }

    /** @param string $mismatch Field or lifecycle condition that breaks the accepted correlation */
    #[DataProvider('mismatchedNameAttempts')]
    public function testRefusesUncorrelatedNamesWithoutChangingTheQueue(string $mismatch): void
    {
        $this->mountNameAttempts();
        $startedAt = TimeHelper::nowMs() - 1000;
        $deadlineAt = $mismatch === 'expired' ? $startedAt : $startedAt + 90000;
        ExecutionContext::setCurrentAgentId(self::INITIATOR_A);
        Hilos::$rt->hilosModerationChecks->actions->open(
            self::OTHER_ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_NOTE, self::TEXT,
        );
        $this->acceptNameAttempt(
            userId: $mismatch === 'user' ? self::USER_ID + 1 : self::USER_ID,
            requestId: $mismatch === 'request' ? 'another-request' : self::REQUEST_ID,
            originAcceptKey: $mismatch === 'origin' ? self::OTHER_ACCEPT_KEY : self::ACCEPT_KEY,
            name: $mismatch === 'content' ? 'Another name' : self::TEXT,
            startedAt: $startedAt,
            deadlineAt: $deadlineAt,
        );
        ExecutionContext::setCurrentAgentId(self::SESSION_HOLDER);
        switch ($mismatch) {
            case 'applying':
                Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->markApplying();
                break;
            case 'refused':
                Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->refuse('This name was not accepted.');
                break;
            case 'done':
                Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->complete();
                break;
            case 'missing':
                Hilos::$rt->hilosProfileNameChanges[self::SESSION_HASH]->actions->drop();
                break;
            case 'stale':
                RtStaleness::mark(HilosProfileNameChange::RT_COLLECTION, [self::SESSION_HASH], microtime(true));
                break;
        }
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_USERS_LIBRARY);
        $queueBefore = Hilos::$rt->hilosModerationChecks->toArray();

        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY,
                self::USER_ID,
                ModerationKindKey::PROFILE_NAME,
                self::TEXT,
                sessionTokenHash: $mismatch === 'hash' ? 'another-hash' : self::SESSION_HASH,
                requestId: self::REQUEST_ID,
                deadlineAt: $mismatch === 'deadline' ? $deadlineAt + 1 : $deadlineAt,
            );
            self::fail('A name check without a matching fresh checking attempt was accepted');
        } catch (InvalidStateException) {
        }
        self::assertSame($queueBefore, Hilos::$rt->hilosModerationChecks->toArray());
    }

    /** @return iterable<string, array{string}> Correlation mismatches and unavailable attempts */
    public static function mismatchedNameAttempts(): iterable
    {
        foreach ([
            'user', 'request', 'origin', 'content', 'deadline', 'expired', 'applying', 'refused', 'done', 'missing', 'stale', 'hash',
        ] as $case) {
            yield $case => [$case];
        }
    }

    public function testAnUndeliveredAttemptIsNotTreatedAsPermissionToCreate(): void
    {
        $this->mountNameAttempts();
        $deadlineAt = TimeHelper::nowMs() + 90000;
        SourceInterestRegistry::register(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION, 'unit-awaiting-name-state');
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION);
        SourceInterestRegistry::register(SourceChange::KIND_RT, HilosProfileNameChange::RT_COLLECTION, 'unit-awaiting-name-state');
        SourceInterestRegistry::readsWhatIsDelivered();
        $queueBefore = Hilos::$rt->hilosModerationChecks->toArray();

        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY, self::USER_ID, ModerationKindKey::PROFILE_NAME, self::TEXT,
                sessionTokenHash: self::SESSION_HASH, requestId: self::REQUEST_ID, deadlineAt: $deadlineAt,
            );
            self::fail('An undelivered attempt was treated as an accepted attempt');
        } catch (RtCollectionNotReadableException $refusal) {
            self::assertStringContainsString(HilosProfileNameChange::RT_COLLECTION, $refusal->getMessage());
            self::assertStringContainsString('its state has not arrived yet', $refusal->getMessage());
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
            SourceInterestRegistry::releaseConsumer('unit-awaiting-name-state');
        }
        self::assertSame($queueBefore, Hilos::$rt->hilosModerationChecks->toArray());
    }

    /**
     * @param ?string $hash Session hash supplied to the queue
     * @param ?string $requestId Request id supplied to the queue
     * @param ?int $deadlineAt Deadline supplied to the queue
     */
    #[DataProvider('partialCorrelations')]
    public function testPartialCorrelationCannotBypassAdmission(?string $hash, ?string $requestId, ?int $deadlineAt): void
    {
        $this->mountNameAttempts();
        $queueBefore = Hilos::$rt->hilosModerationChecks->toArray();
        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY, self::USER_ID, ModerationKindKey::PROFILE_NAME, self::TEXT,
                sessionTokenHash: $hash, requestId: $requestId, deadlineAt: $deadlineAt,
            );
            self::fail('An incomplete correlation was accepted');
        } catch (InvalidArgumentException) {
        }
        self::assertSame($queueBefore, Hilos::$rt->hilosModerationChecks->toArray());
    }

    /** @return iterable<string, array{?string, ?string, ?int}> Incomplete correlation arguments */
    public static function partialCorrelations(): iterable
    {
        yield 'hash only' => [self::SESSION_HASH, null, null];
        yield 'missing deadline' => [self::SESSION_HASH, self::REQUEST_ID, null];
        yield 'missing request' => [self::SESSION_HASH, null, PHP_INT_MAX];
        yield 'request alone' => [null, self::REQUEST_ID, null];
        yield 'deadline alone' => [null, null, PHP_INT_MAX];
        yield 'missing hash' => [null, self::REQUEST_ID, PHP_INT_MAX];
        yield 'empty hash' => ['', self::REQUEST_ID, PHP_INT_MAX];
        yield 'empty request' => [self::SESSION_HASH, '', PHP_INT_MAX];
    }

    public function testSessionCorrelationIsReservedForTheFrameworkNameKind(): void
    {
        $this->mountNameAttempts();
        $deadlineAt = TimeHelper::nowMs() + 90000;
        $this->acceptNameAttempt(deadlineAt: $deadlineAt);
        ExecutionContext::setCurrentAgentId(self::INITIATOR_A);
        $queueBefore = Hilos::$rt->hilosModerationChecks->toArray();
        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_NOTE, self::TEXT,
                sessionTokenHash: self::SESSION_HASH, requestId: self::REQUEST_ID, deadlineAt: $deadlineAt,
            );
            self::fail('A project kind opened a session-bound check');
        } catch (InvalidArgumentException) {
        }
        self::assertSame($queueBefore, Hilos::$rt->hilosModerationChecks->toArray());
    }

    /** Mounts AUTH storage while keeping the legacy tests on their moderation-only fixture. */
    private function mountNameAttempts(): void
    {
        self::bindAppClass(ModerationNameCheckTestHilos::class);
        Hilos::$rt->mountFeatureRuntime([new AuthFeature()]);
        Hilos::$rt->bindStateCollectionNames();
        RtTruthSourceRegistry::register(HilosProfileNameChange::RT_COLLECTION, TruthSourceKeys::all(), self::SESSION_HOLDER);
        RtTruthSourceRegistry::register(
            HilosModerationCheck::RT_COLLECTION,
            TruthSourceKeys::set(HilosAgentType::HILOS_USERS_LIBRARY),
            HilosAgentType::HILOS_USERS_LIBRARY,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove),
        );
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_USERS_LIBRARY);
    }

    /**
     * @param int $userId Accepted person id
     * @param string $requestId Accepted request id
     * @param string $originAcceptKey Accepted origin connection
     * @param string $name Accepted name
     * @param ?int $startedAt Accepted start in epoch milliseconds, or current time
     * @param ?int $deadlineAt Accepted deadline in epoch milliseconds, or a future deadline
     */
    private function acceptNameAttempt(
        int $userId = self::USER_ID,
        string $requestId = self::REQUEST_ID,
        string $originAcceptKey = self::ACCEPT_KEY,
        string $name = self::TEXT,
        ?int $startedAt = null,
        ?int $deadlineAt = null,
    ): void {
        ExecutionContext::setCurrentAgentId(self::SESSION_HOLDER);
        Hilos::$rt->hilosProfileNameChanges->actions->open(
            self::SESSION_HASH, $userId, $requestId, $originAcceptKey, $name,
            $startedAt ?? TimeHelper::nowMs(), $deadlineAt ?? TimeHelper::nowMs() + 90000,
        );
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_USERS_LIBRARY);
    }

    /**
     * @return array<string, mixed> Sync row of a text check
     */
    private function textRow(): array
    {
        return HilosModerationCheck::create(
            self::ACCEPT_KEY,
            self::USER_ID,
            ModerationCheckTestDirectory::A_NOTE,
            'request-1',
            self::INITIATOR_A,
            self::TEXT,
            null,
            1000,
            91000,
        )->toArray();
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

/**
 * Project kinds received by two initiators of their own.
 */
final class ModerationCheckTestDirectory extends ModerationKindDirectory
{
    public const string A_NOTE = 'initiator_a_note';
    public const string A_ATTACHMENTS = 'initiator_a_attachments';
    public const string B_NOTE = 'initiator_b_note';
    public const string B_PHOTO = 'initiator_b_photo';

    /**
     * @return list<ModerationKind> Framework kinds followed by the two initiators' kinds
     */
    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            new ModerationKind(self::A_NOTE, 'Note', ModerationInput::TEXT, ['spam'], ['Allow notes.'], HilosModerationCheckTest::INITIATOR_A),
            new ModerationKind(
                self::A_ATTACHMENTS,
                'Attachments',
                ModerationInput::TEXT_WITH_FILES,
                ['spam'],
                ['Allow attachments.'],
                HilosModerationCheckTest::INITIATOR_A,
            ),
            new ModerationKind(self::B_NOTE, 'Caption', ModerationInput::TEXT, ['spam'], ['Allow captions.'], HilosModerationCheckTest::INITIATOR_B),
            new ModerationKind(self::B_PHOTO, 'Photo', ModerationInput::IMAGE, ['nudity'], ['Allow photos.'], HilosModerationCheckTest::INITIATOR_B),
        ];
    }
}

/**
 * Facade standing in for a project that moderates; abstract because no layer is built from it.
 */
abstract class ModerationCheckTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::MODERATION];

    protected const string MODERATION_KIND_DIRECTORY = ModerationCheckTestDirectory::class;
}

/**
 * Facade standing in for the same project with moderation switched off.
 */
abstract class ModerationCheckOffTestHilos extends Hilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationCheckTestDirectory::class;
}

/** A project that admits session name-change attempts as well as moderation checks. */
abstract class ModerationNameCheckTestHilos extends ModerationCheckTestHilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::MODERATION];
}

/**
 * Settings whose check timeout the case scripts, as an administrator's saved row would.
 */
final class ModerationCheckTestSettings extends SettingsAccessor
{
    /** Seconds a whole check may take, as the case has set them. */
    public static int $timeoutSec = ModerationSettingsCatalog::DEFAULT_CHECK_TIMEOUT_SEC;

    /**
     * @param string $key Setting key
     * @return mixed The scripted timeout, or the stored value of any other key
     */
    public function effectiveValueFor(string $key): mixed
    {
        return $key === ModerationSettingsCatalog::CHECK_TIMEOUT_SEC ? self::$timeoutSec : parent::effectiveValueFor($key);
    }
}

/**
 * A project that mounts the moderation checks and nothing else, through the feature that owns them.
 */
final class ModerationCheckRtContext extends RtContext
{
    /**
     * Mounts the checks with their framework representation.
     */
    public function configure(): void
    {
        new ModerationFeature()->mount($this);
    }
}
