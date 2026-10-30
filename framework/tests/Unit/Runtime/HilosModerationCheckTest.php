<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Error;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Files\Upload\DTO\UploadSnapshotData;
use Hilos\Files\Upload\UploadSnapshotCollection;
use Hilos\Hilos;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\RtSyncApplicator;
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
        $this->assertSame([], $restored->clientUploadIds);
        $this->assertFalse($restored->imageCopy);
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

    /**
     * @param string $kind Kind the check is opened for
     * @param ?string $text Text handed over
     * @param ?string $clientUploadId Image upload handed over
     * @param list<string> $clientUploadIds Ordered attachment uploads handed over
     * @param bool $imageCopy Whether to check an image copy
     */
    #[DataProvider('acceptedInputs')]
    public function testAcceptedInputsSurviveTheViewSyncAndSnapshot(
        string $kind,
        ?string $text,
        ?string $clientUploadId,
        array $clientUploadIds,
        bool $imageCopy,
    ): void {
        ExecutionContext::setCurrentAgentId(
            $kind === ModerationCheckTestDirectory::B_PHOTO ? self::INITIATOR_B : self::INITIATOR_A,
        );
        // All arguments are positional to keep both the old prefix and the appended fields covered.
        $check = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, $kind, $text, $clientUploadId, null, null, null, $clientUploadIds, $imageCopy,
        );
        $row = $check->toArray();
        $id = $check->getId();

        self::assertSame($text, $check->text);
        self::assertSame($clientUploadId, $check->clientUploadId);
        self::assertSame($clientUploadIds, $check->clientUploadIds);
        self::assertSame($imageCopy, $check->imageCopy);
        self::assertNull($check->uploadHoldId);
        self::assertCount(0, $check->uploadSnapshots);
        self::assertSame([], $row[HilosModerationCheck::uploadSnapshots]);
        self::assertSame($clientUploadIds, $row[HilosModerationCheck::clientUploadIds]);
        self::assertSame($imageCopy, $row[HilosModerationCheck::imageCopy]);
        self::assertSame($row, HilosModerationCheck::fromRow($row)->toArray());

        $check->actions->forget();
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $kind));
        $created = new RtSyncCreatedSignalData(HilosModerationCheck::RT_COLLECTION, $id, $row);
        RtSyncApplicator::applyCreated(RtSyncCreatedSignalData::fromArray($created->toArray()), false);

        self::assertSame($clientUploadIds, Hilos::$rt->hilosModerationChecks[$id]->clientUploadIds);
        self::assertSame($imageCopy, Hilos::$rt->hilosModerationChecks[$id]->imageCopy);
        self::assertSame($row, Hilos::$rt->hilosModerationChecks[$id]->toArray());
        self::assertSame([$id => $row], RtSnapshot::rows(HilosModerationCheck::RT_COLLECTION));

        RtSnapshot::replace(HilosModerationCheck::RT_COLLECTION, [$id => $row]);
        self::assertSame($clientUploadIds, Hilos::$rt->hilosModerationChecks[$id]->clientUploadIds);
        self::assertSame($imageCopy, Hilos::$rt->hilosModerationChecks[$id]->imageCopy);
        self::assertSame($row, Hilos::$rt->hilosModerationChecks[$id]->toArray());
    }

    /** @return iterable<string, array{string, ?string, ?string, list<string>, bool}> Valid input combinations */
    public static function acceptedInputs(): iterable
    {
        yield 'text' => [ModerationCheckTestDirectory::A_NOTE, self::TEXT, null, [], false];
        yield 'empty text' => [ModerationCheckTestDirectory::A_NOTE, '', null, [], false];
        yield 'text with no files' => [ModerationCheckTestDirectory::A_ATTACHMENTS, self::TEXT, null, [], false];
        yield 'text with ordered files' => [
            ModerationCheckTestDirectory::A_ATTACHMENTS, self::TEXT, null, ['upload-2', self::UPLOAD, '0', '01', '1'], false,
        ];
        yield 'empty text with no files' => [ModerationCheckTestDirectory::A_ATTACHMENTS, '', null, [], false];
        yield 'empty text with files' => [ModerationCheckTestDirectory::A_ATTACHMENTS, '', null, [self::UPLOAD], false];
        yield 'original image' => [ModerationCheckTestDirectory::B_PHOTO, null, self::UPLOAD, [], false];
        yield 'image copy' => [ModerationCheckTestDirectory::B_PHOTO, null, self::UPLOAD, [], true];
    }

    /**
     * @param string $field New field to omit
     */
    #[DataProvider('newFields')]
    public function testRefusesASyncRowThatLostANewField(string $field): void
    {
        $row = $this->textRow();
        unset($row[$field]);

        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage($field);
        HilosModerationCheck::fromRow($row);
    }

    /** @return iterable<string, array{string}> New required sync keys */
    public static function newFields(): iterable
    {
        yield 'uploads' => [HilosModerationCheck::clientUploadIds];
        yield 'image copy' => [HilosModerationCheck::imageCopy];
        yield 'hold id' => [HilosModerationCheck::uploadHoldId];
        yield 'snapshots' => [HilosModerationCheck::uploadSnapshots];
    }

    /**
     * @param string $field Field carrying a wrong type
     * @param mixed $value Mistyped value
     */
    #[DataProvider('mistypedNewFields')]
    public function testRefusesMistypedNewFieldsWithoutCoercion(string $field, mixed $value): void
    {
        $row = $this->textRow();
        $row[$field] = $value;

        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage($field);
        HilosModerationCheck::fromRow($row);
    }

    /** @return iterable<string, array{string, mixed}> Wrong types for the new fields */
    public static function mistypedNewFields(): iterable
    {
        foreach ([null, false, self::UPLOAD, 1, [1], [false], [null], [[self::UPLOAD]], ['file' => self::UPLOAD]] as $index => $value) {
            yield 'upload ids ' . $index => [HilosModerationCheck::clientUploadIds, $value];
        }
        foreach ([null, 0, 1, 'false', 'true', []] as $index => $value) {
            yield 'image copy ' . $index => [HilosModerationCheck::imageCopy, $value];
        }
        foreach ([false, 1, [], 'orphan-token', ''] as $index => $value) {
            yield 'hold token ' . $index => [HilosModerationCheck::uploadHoldId, $value];
        }
        foreach ([null, false, 1, '[]', ['id' => []], ['upload'], [[]]] as $index => $value) {
            yield 'snapshots ' . $index => [HilosModerationCheck::uploadSnapshots, $value];
        }
    }

    /**
     * @param string $field Field whose write must be refused
     * @param mixed $value Replacement value
     */
    #[DataProvider('immutableNewFields')]
    public function testNewFieldsCannotBeRewritten(string $field, mixed $value): void
    {
        $check = HilosModerationCheck::fromRow($this->textRow());

        $this->expectException(Error::class);
        $check->$field = $value;
    }

    /** @return iterable<string, array{string, mixed}> Attempted edits of immutable fields */
    public static function immutableNewFields(): iterable
    {
        yield 'uploads' => [HilosModerationCheck::clientUploadIds, [self::UPLOAD]];
        yield 'image copy' => [HilosModerationCheck::imageCopy, true];
        yield 'hold token' => [HilosModerationCheck::uploadHoldId, self::REQUEST_ID];
        yield 'snapshots' => [HilosModerationCheck::uploadSnapshots, new UploadSnapshotCollection()];
    }

    public function testAttachmentArraysAreDetachedAndDiffsCannotRewriteTheSnapshot(): void
    {
        $uploadIds = ['upload-2', self::UPLOAD];
        $check = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_ATTACHMENTS, '',
            clientUploadIds: $uploadIds,
        );
        $row = $check->toArray();
        $state = HilosModerationCheck::fromRow($row);
        $uploadIds[] = 'later-upload';
        $viewIds = $check->clientUploadIds;
        $viewIds[] = 'another-upload';
        $row[HilosModerationCheck::clientUploadIds][] = 'snapshot-edit';
        $state->applyDiff([HilosModerationCheck::clientUploadIds => [], HilosModerationCheck::imageCopy => true]);

        self::assertSame(['upload-2', self::UPLOAD], $check->clientUploadIds);
        self::assertSame($check->toArray(), $state->toArray());
        self::assertFalse($check->imageCopy);
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
        self::assertSame([
            HilosModerationCheck::acceptKey => self::ACCEPT_KEY,
            HilosModerationCheck::userId => self::USER_ID,
            HilosModerationCheck::kind => ModerationCheckTestDirectory::A_NOTE,
            HilosModerationCheck::requestId => 'request-1',
            HilosModerationCheck::verdictTo => self::INITIATOR_A,
            HilosModerationCheck::text => self::TEXT,
            HilosModerationCheck::clientUploadId => null,
            HilosModerationCheck::startedAt => 1000,
            HilosModerationCheck::deadlineAt => 91000,
            HilosModerationCheck::sessionTokenHash => null,
            HilosModerationCheck::clientUploadIds => [],
            HilosModerationCheck::imageCopy => false,
            HilosModerationCheck::uploadHoldId => null,
            HilosModerationCheck::uploadSnapshots => [],
        ], $restored->toArray());
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
        $this->assertSame([], $check->clientUploadIds);
        $this->assertFalse($check->imageCopy);
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

    public function testConnectionChecksKeepOneDeadlineAcrossStagesAndSettingsChanges(): void
    {
        $deadlineAt = TimeHelper::nowMs() + 15000;
        $text = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_ATTACHMENTS, self::TEXT,
            deadlineAt: $deadlineAt, clientUploadIds: [self::UPLOAD],
        );
        self::assertSame($deadlineAt, $text->deadlineAt);
        ModerationCheckTestSettings::$timeoutSec = self::RAISED_TIMEOUT_SEC;
        ExecutionContext::setCurrentAgentId(self::INITIATOR_B);
        $image = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::B_PHOTO, clientUploadId: self::UPLOAD,
            deadlineAt: $text->deadlineAt, imageCopy: true,
        );

        self::assertSame($deadlineAt, $image->deadlineAt);
        self::assertSame($deadlineAt, $text->deadlineAt);
        self::assertNotSame($text->requestId, $image->requestId);
        self::assertNull($image->sessionTokenHash);
    }

    public function testExplicitConnectionDeadlineNeedsNoTimeoutSetting(): void
    {
        Hilos::$setting = null;
        $deadlineAt = PHP_INT_MAX;
        $check = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_NOTE, self::TEXT,
            deadlineAt: $deadlineAt,
        );

        self::assertSame($deadlineAt, $check->deadlineAt);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $check->requestId);
    }

    /**
     * @param int $deadlineAt Expired deadline to refuse
     */
    #[DataProvider('expiredDeadlines')]
    public function testRefusesAnExpiredExplicitConnectionDeadline(int $deadlineAt): void
    {
        $before = Hilos::$rt->hilosModerationChecks->toArray();
        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_NOTE, self::TEXT,
                deadlineAt: $deadlineAt,
            );
            self::fail('An expired deadline was accepted');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame('A moderation check deadline must be in the future.', $refusal->getMessage());
        }
        self::assertSame($before, Hilos::$rt->hilosModerationChecks->toArray());
    }

    /** @return iterable<string, array{int}> Deadlines at or before admission */
    public static function expiredDeadlines(): iterable
    {
        yield 'past' => [TimeHelper::nowMs() - 1];
        yield 'current' => [TimeHelper::nowMs()];
        yield 'zero' => [0];
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

    /**
     * @param string $kind Kind the check is opened for
     * @param ?string $text Text handed over
     * @param ?string $clientUploadId Image upload handed over
     * @param array<array-key, mixed> $clientUploadIds Attachment input, including malformed lists
     * @param bool $imageCopy Whether to check an image copy
     */
    #[DataProvider('invalidExtendedInputs')]
    public function testRefusesInvalidExtendedInputsWithoutChangingTheQueue(
        string $kind,
        ?string $text,
        ?string $clientUploadId,
        array $clientUploadIds,
        bool $imageCopy,
    ): void {
        ExecutionContext::setCurrentAgentId(
            $kind === ModerationCheckTestDirectory::B_PHOTO ? self::INITIATOR_B : self::INITIATOR_A,
        );
        $before = Hilos::$rt->hilosModerationChecks->toArray();
        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY, self::USER_ID, $kind, $text, $clientUploadId,
                clientUploadIds: $clientUploadIds, imageCopy: $imageCopy,
            );
            self::fail('An invalid extended input was accepted');
        } catch (InvalidArgumentException) {
        }
        self::assertSame($before, Hilos::$rt->hilosModerationChecks->toArray());
    }

    /** @return iterable<string, array{string, ?string, ?string, array<array-key, mixed>, bool}> Invalid extended inputs */
    public static function invalidExtendedInputs(): iterable
    {
        yield 'text with attachments' => [ModerationCheckTestDirectory::A_NOTE, self::TEXT, null, [self::UPLOAD], false];
        yield 'text with image copy' => [ModerationCheckTestDirectory::A_NOTE, self::TEXT, null, [], true];
        yield 'files without text' => [ModerationCheckTestDirectory::A_ATTACHMENTS, null, null, [self::UPLOAD], false];
        yield 'files without text or uploads' => [ModerationCheckTestDirectory::A_ATTACHMENTS, null, null, [], false];
        yield 'files with singular upload' => [ModerationCheckTestDirectory::A_ATTACHMENTS, self::TEXT, self::UPLOAD, [], false];
        yield 'files with empty singular upload' => [ModerationCheckTestDirectory::A_ATTACHMENTS, self::TEXT, '', [], false];
        yield 'files with image copy' => [ModerationCheckTestDirectory::A_ATTACHMENTS, self::TEXT, null, [], true];
        yield 'image with attachments' => [ModerationCheckTestDirectory::B_PHOTO, null, self::UPLOAD, [self::UPLOAD], false];
        yield 'copy with text' => [ModerationCheckTestDirectory::B_PHOTO, '', self::UPLOAD, [], true];
        yield 'copy without upload' => [ModerationCheckTestDirectory::B_PHOTO, null, null, [], true];
        foreach ([
            [''], [self::UPLOAD, self::UPLOAD], [1], [false], [null], [[self::UPLOAD]], ['file' => self::UPLOAD], [1 => self::UPLOAD],
        ] as $index => $uploads) {
            yield 'malformed uploads ' . $index => [ModerationCheckTestDirectory::A_ATTACHMENTS, self::TEXT, null, $uploads, false];
        }
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

    public function testAnInitiatorCannotOpenTextWithFilesInAnotherSet(): void
    {
        ExecutionContext::setCurrentAgentId(self::INITIATOR_B);
        $before = Hilos::$rt->hilosModerationChecks->toArray();
        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_ATTACHMENTS, '',
                clientUploadIds: [self::UPLOAD],
            );
            self::fail('An initiator opened a text-with-files check in another set');
        } catch (RtTruthSourceWriteNotAllowedException $refusal) {
            self::assertStringContainsString("it holds set '" . self::INITIATOR_B . "'", $refusal->getMessage());
        }
        self::assertSame($before, Hilos::$rt->hilosModerationChecks->toArray());
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
        self::assertSame([], $check->clientUploadIds);
        self::assertFalse($check->imageCopy);
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

    public function testSnapshotStagesShareTheFirstTokenAndKeepNewRequestIdsAndTheFixedDeadline(): void
    {
        $snapshots = new UploadSnapshotCollection(
            new UploadSnapshotData('upload-2', str_repeat('b', 32), hash('sha256', 'second')),
            new UploadSnapshotData(self::UPLOAD, str_repeat('a', 32), hash('sha256', 'first')),
        );
        $deadlineAt = TimeHelper::nowMs() + 90000;
        // The appended arguments preserve every old positional argument.
        $first = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_ATTACHMENTS, '', null,
            null, null, $deadlineAt, ['upload-2', self::UPLOAD], false, $snapshots, null,
        );
        self::assertSame($first->requestId, $first->uploadHoldId);
        self::assertSame($snapshots, $first->uploadSnapshots);
        $row = $first->toArray();
        $state = HilosModerationCheck::fromRow($row);
        $state->applyDiff([HilosModerationCheck::uploadSnapshots => [], HilosModerationCheck::uploadHoldId => 'changed']);
        self::assertSame($row, $state->toArray());
        $first->actions->forget();
        $created = new RtSyncCreatedSignalData(HilosModerationCheck::RT_COLLECTION, $first->getId(), $row);
        RtSyncApplicator::applyCreated(RtSyncCreatedSignalData::fromArray($created->toArray()), false);
        self::assertSame($snapshots->toArray(), Hilos::$rt->hilosModerationChecks[$first->getId()]->uploadSnapshots->toArray());
        self::assertSame($first->uploadHoldId, Hilos::$rt->hilosModerationChecks[$first->getId()]->uploadHoldId);
        RtSnapshot::replace(HilosModerationCheck::RT_COLLECTION, [$first->getId() => $row]);
        self::assertSame([$first->getId() => $row], RtSnapshot::rows(HilosModerationCheck::RT_COLLECTION));

        ExecutionContext::setCurrentAgentId(self::INITIATOR_B);
        $next = Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::B_PHOTO, null, self::UPLOAD,
            null, null, $deadlineAt, [], true, $snapshots, $first->uploadHoldId,
        );
        self::assertNotSame($first->requestId, $next->requestId);
        self::assertSame($first->uploadHoldId, $next->uploadHoldId);
        self::assertSame($deadlineAt, $next->deadlineAt);
        self::assertSame([], $next->clientUploadIds);
        self::assertSame($snapshots->toArray(), $next->uploadSnapshots->toArray());
    }

    /**
     * @param string $case Invalid snapshot/input correlation
     */
    #[DataProvider('mismatchedSnapshots')]
    public function testRefusesSnapshotInputMismatchBeforeOpeningAQueueRow(string $case): void
    {
        $snapshots = new UploadSnapshotCollection(new UploadSnapshotData(self::UPLOAD, str_repeat('a', 32), hash('sha256', 'first')));
        $kind = ModerationCheckTestDirectory::A_ATTACHMENTS;
        $text = self::TEXT;
        $singular = null;
        $ids = [self::UPLOAD];
        $token = null;
        if ($case === 'text only') {
            $kind = ModerationCheckTestDirectory::A_NOTE;
            $ids = [];
        } elseif ($case === 'image outside snapshot') {
            $kind = ModerationCheckTestDirectory::B_PHOTO;
            ExecutionContext::setCurrentAgentId(self::INITIATOR_B);
            $text = null;
            $singular = 'other-upload';
            $ids = [];
        } elseif ($case === 'wrong order') {
            $snapshots = new UploadSnapshotCollection(
                new UploadSnapshotData('upload-2', str_repeat('b', 32), hash('sha256', 'second')),
                ...iterator_to_array($snapshots),
            );
            $ids = [self::UPLOAD, 'upload-2'];
        } elseif ($case === 'missing attachment') {
            $ids = [self::UPLOAD, 'upload-2'];
        } elseif ($case === 'empty token') {
            $token = '';
        } else {
            $snapshots = $case === 'null snapshot with token' ? null : new UploadSnapshotCollection();
            $token = self::REQUEST_ID;
        }
        $before = Hilos::$rt->hilosModerationChecks->toArray();
        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                self::ACCEPT_KEY, self::USER_ID, $kind, $text, $singular,
                clientUploadIds: $ids, uploadSnapshots: $snapshots, uploadHoldId: $token,
            );
            self::fail('A snapshot that did not match the input was accepted.');
        } catch (InvalidArgumentException) {
        }
        self::assertSame($before, Hilos::$rt->hilosModerationChecks->toArray());
    }

    /** @return iterable<string, array{string}> Snapshot/input mismatches */
    public static function mismatchedSnapshots(): iterable
    {
        foreach (['text only', 'image outside snapshot', 'wrong order', 'missing attachment', 'empty token',
            'empty snapshot with token', 'null snapshot with token'] as $case) {
            yield $case => [$case];
        }
    }

    public function testSnapshotCannotSupplyAChosenRequestIdWithoutSessionCorrelation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_ATTACHMENTS, self::TEXT,
            requestId: self::REQUEST_ID, clientUploadIds: [self::UPLOAD],
            uploadSnapshots: new UploadSnapshotCollection(new UploadSnapshotData(self::UPLOAD, str_repeat('a', 32), hash('sha256', 'first'))),
        );
    }

    public function testSnapshotCreationStillBelongsOnlyToTheKindsInitiator(): void
    {
        ExecutionContext::setCurrentAgentId(self::INITIATOR_B);
        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationCheckTestDirectory::A_ATTACHMENTS, self::TEXT,
            clientUploadIds: [self::UPLOAD],
            uploadSnapshots: new UploadSnapshotCollection(new UploadSnapshotData(self::UPLOAD, str_repeat('a', 32), hash('sha256', 'first'))),
        );
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
