<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Files\Upload\DTO\UploadStateSignalData;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Hilos;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosUpload as ViewHilosUpload;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pins the owner commands and their serialized state, without enabling agent enforcement. */
final class HilosUploadHoldTest extends TestCase
{
    private const string ACCEPT_KEY = 'hold-connection';
    private const string UPLOAD_ID = 'file-1';
    private const string TOKEN = 'first-stage-request';
    private const string NEXT_TOKEN = 'another-attempt';
    private ?RtContext $previousRuntime = null;
    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRuntime = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        Hilos::$rt = new class extends RtContext {
            /** Mounts only the framework upload representation. */
            public function configure(): void
            {
                new UploadsFeature()->mount($this);
            }
        };
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), HilosAgentType::HILOS_UPLOADS);
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_UPLOADS);
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_UPLOADS);
        SourceChangeBus::reset();
        RtStaleness::reset();
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    public function testHoldsApproveReleaseAndFreshAttemptPreserveTheByteIdentity(): void
    {
        $upload = $this->completeUpload();
        self::assertNull($upload->uploadHoldId);
        self::assertNull($upload->uploadHoldDeadlineAt);
        self::assertFalse($upload->uploadHoldApproved);
        $snapshot = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, [self::UPLOAD_ID]);
        $browserRow = UploadStateSignalData::fromUpload($upload)->toArray();

        $upload->actions->hold(self::TOKEN, PHP_INT_MAX);
        $upload->actions->approveHold(self::TOKEN);
        $approved = $upload->toArray();
        $upload->actions->hold(self::TOKEN, PHP_INT_MAX);
        $upload->actions->approveHold(self::TOKEN);
        $upload->actions->releaseHold(self::NEXT_TOKEN);
        self::assertSame($approved, $upload->toArray());
        self::assertTrue($upload->uploadHoldApproved);
        self::assertSame(PHP_INT_MAX, $upload->uploadHoldDeadlineAt);

        $upload->actions->releaseHold(self::TOKEN);
        $released = $upload->toArray();
        $upload->actions->releaseHold(self::TOKEN);
        self::assertSame($released, $upload->toArray());
        self::assertSame(self::TOKEN, $upload->uploadHoldId);
        self::assertNull($upload->uploadHoldDeadlineAt);
        self::assertFalse($upload->uploadHoldApproved);
        $upload->actions->hold(self::NEXT_TOKEN, PHP_INT_MAX);
        $upload->actions->releaseHold(self::TOKEN);
        self::assertSame(self::NEXT_TOKEN, $upload->uploadHoldId);
        self::assertFalse($upload->uploadHoldApproved);
        self::assertSame($snapshot->toArray(), Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, [self::UPLOAD_ID])->toArray());
        self::assertSame($browserRow, UploadStateSignalData::fromUpload($upload)->toArray());
        self::assertSame($upload->toArray(), HilosUpload::fromRow($upload->toArray())->toArray());
    }

    public function testHoldSyncDiffRoundTripsAndPartialUpdatesKeepTheOtherMarks(): void
    {
        $upload = $this->completeUpload();
        $initial = $upload->toArray();
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $upload->actions->hold(self::TOKEN, PHP_INT_MAX);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertInstanceOf(RtSyncUpdatedSignalData::class, $signal?->data);
        $heldDiff = RtSyncUpdatedSignalData::fromArray($signal->data->toArray());
        self::assertSame([
            HilosUpload::uploadHoldId => self::TOKEN,
            HilosUpload::uploadHoldDeadlineAt => PHP_INT_MAX,
        ], $heldDiff->row);
        RtSnapshot::replace(HilosUpload::RT_COLLECTION, [$upload->getId() => $initial]);
        RtSyncApplicator::applyUpdated($heldDiff, false);
        self::assertSame(self::TOKEN, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, self::UPLOAD_ID)->uploadHoldId);
        $upload = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, self::UPLOAD_ID);
        $upload->actions->approveHold(self::TOKEN);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertInstanceOf(RtSyncUpdatedSignalData::class, $signal?->data);
        self::assertSame([HilosUpload::uploadHoldApproved => true], $signal->data->row);

        $state = HilosUpload::fromRow($upload->toArray());
        $state->applyDiff([HilosUpload::updatedAt => 3]);
        self::assertSame(self::TOKEN, $state->uploadHoldId);
        self::assertSame(PHP_INT_MAX, $state->uploadHoldDeadlineAt);
        self::assertTrue($state->uploadHoldApproved);
        $state->applyDiff([HilosUpload::uploadHoldDeadlineAt => null, HilosUpload::uploadHoldApproved => false]);
        self::assertSame(self::TOKEN, $state->uploadHoldId);
        self::assertNull($state->uploadHoldDeadlineAt);
        self::assertSame($state->toArray(), HilosUpload::fromRow($state->toArray())->toArray());

        $upload->actions->hold(self::TOKEN, PHP_INT_MAX);
        $upload->actions->approveHold(self::TOKEN);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @param string $case Invalid lifecycle transition */
    #[DataProvider('invalidTransitions')]
    public function testRefusesInvalidTransitionsWithoutChangingAnyFields(string $case): void
    {
        $upload = $this->completeUpload();
        $command = 'hold';
        $arguments = [self::TOKEN, PHP_INT_MAX];
        $exception = InvalidStateException::class;
        switch ($case) {
            case 'empty token':
                $arguments = ['', PHP_INT_MAX];
                $exception = InvalidArgumentException::class;
                break;
            case 'expired deadline':
                $arguments = [self::TOKEN, 1];
                $exception = InvalidArgumentException::class;
                break;
            case 'incomplete bytes':
                $row = $upload->toArray();
                $row[HilosUpload::phase] = UploadPhase::UPLOADING->value;
                break;
            case 'missing tmp':
            case 'missing hash':
                $row = $upload->toArray();
                $row[$case === 'missing tmp' ? HilosUpload::tmpIndex : HilosUpload::contentHash] = null;
                break;
            case 'malformed tmp':
                $row = $upload->toArray();
                $row[HilosUpload::tmpIndex] = '../outside';
                $exception = InvalidFormatException::class;
                break;
            default:
                $upload->actions->hold(self::TOKEN, PHP_INT_MAX);
                if ($case === 'another active token') {
                    $arguments = [self::NEXT_TOKEN, PHP_INT_MAX];
                } elseif ($case === 'changed deadline') {
                    $arguments = [self::TOKEN, PHP_INT_MAX - 1];
                } elseif ($case === 'reopen tombstone') {
                    $upload->actions->releaseHold(self::TOKEN);
                } else {
                    $command = 'approveHold';
                    $arguments = [$case === 'approve another token' ? self::NEXT_TOKEN : self::TOKEN];
                    if ($case === 'approve released') {
                        $upload->actions->releaseHold(self::TOKEN);
                    } elseif ($case === 'approve expired' || $case === 'approve expired approved') {
                        $row = $upload->toArray();
                        $row[HilosUpload::uploadHoldDeadlineAt] = 1;
                        $row[HilosUpload::uploadHoldApproved] = $case === 'approve expired approved';
                    }
                }
        }
        if (isset($row)) {
            RtSnapshot::replace(HilosUpload::RT_COLLECTION, [$upload->getId() => $row]);
            $upload = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, self::UPLOAD_ID);
        }
        $before = $upload->toArray();
        try {
            $upload->actions->$command(...$arguments);
            self::fail('An invalid hold transition was accepted.');
        } catch (InvalidArgumentException|InvalidStateException|InvalidFormatException $refusal) {
            self::assertInstanceOf($exception, $refusal);
        }
        self::assertSame($before, $upload->toArray());
    }

    /** @return iterable<string, array{string}> Invalid hold transitions */
    public static function invalidTransitions(): iterable
    {
        foreach ([
            'empty token', 'expired deadline', 'incomplete bytes', 'missing tmp', 'missing hash', 'malformed tmp',
            'another active token', 'changed deadline', 'reopen tombstone', 'approve another token', 'approve released',
            'approve expired', 'approve expired approved',
        ] as $case) {
            yield $case => [$case];
        }
    }

    /** @param bool $removeOnly Whether the owner has removal permission but no update permission */
    #[DataProvider('unauthorizedWriters')]
    public function testEveryCommandChecksOwnershipEvenWhenItWouldDoNothing(bool $removeOnly): void
    {
        $upload = $this->completeUpload();
        $upload->actions->hold(self::TOKEN, PHP_INT_MAX);
        $upload->actions->approveHold(self::TOKEN);
        $before = $upload->toArray();
        if ($removeOnly) {
            RtTruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_UPLOADS);
            RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), HilosAgentType::HILOS_UPLOADS,
                TruthSourceOperations::of(TruthSourceOperation::Remove));
        } else {
            ExecutionContext::setCurrentAgentId('not-the-upload-owner');
        }
        foreach (['hold' => [self::TOKEN, PHP_INT_MAX], 'approveHold' => [self::TOKEN], 'releaseHold' => [self::NEXT_TOKEN]] as $method => $args) {
            try {
                $upload->actions->$method(...$args);
                self::fail('A caller without ownership updated upload hold marks.');
            } catch (RtTruthSourceWriteNotAllowedException) {
            }
            self::assertSame($before, $upload->toArray());
        }
    }

    /** @return iterable<string, array{bool}> Wrong agent and wrong operation */
    public static function unauthorizedWriters(): iterable
    {
        yield 'foreign agent' => [false];
        yield 'owner without update' => [true];
    }

    /** @param array<string, mixed> $marks Malformed full-row and partial hold fields */
    #[DataProvider('invalidMarks')]
    public function testStrictReadersRefuseInvalidMarksBeforeMutatingTheHold(array $marks): void
    {
        $state = HilosUpload::create(self::ACCEPT_KEY, self::UPLOAD_ID, 'unit', 7, 'file.txt', 'text/plain', 4, str_repeat('a', 32), 1);
        $before = $state->toArray();
        foreach (['full', 'diff'] as $boundary) {
            try {
                if ($boundary === 'full') {
                    HilosUpload::fromRow(array_replace($before, $marks));
                } else {
                    $state->applyDiff($marks);
                }
                self::fail('Malformed hold marks were accepted.');
            } catch (InvalidFormatException) {
            }
            self::assertSame($before, $state->toArray());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Wrong types and contradictory marks */
    public static function invalidMarks(): iterable
    {
        foreach ([false, 1, [], ''] as $index => $token) {
            yield 'token ' . $index => [[HilosUpload::uploadHoldId => $token]];
        }
        foreach (['1000', true, 0, -1, []] as $index => $deadline) {
            yield 'deadline ' . $index => [[HilosUpload::uploadHoldId => self::TOKEN, HilosUpload::uploadHoldDeadlineAt => $deadline]];
        }
        foreach ([null, 'false', 0, 1, []] as $index => $approved) {
            yield 'approval ' . $index => [[HilosUpload::uploadHoldApproved => $approved]];
        }
        yield 'deadline without token' => [[HilosUpload::uploadHoldDeadlineAt => PHP_INT_MAX]];
        yield 'approval without hold' => [[HilosUpload::uploadHoldApproved => true]];
        yield 'approved tombstone' => [[HilosUpload::uploadHoldId => self::TOKEN, HilosUpload::uploadHoldApproved => true]];
    }

    public function testFullRowsRequireAllNewKeysEvenWhenTheyAreNullable(): void
    {
        $row = $this->completeUpload()->toArray();
        foreach ([HilosUpload::uploadHoldId, HilosUpload::uploadHoldDeadlineAt, HilosUpload::uploadHoldApproved] as $field) {
            $missing = $row;
            unset($missing[$field]);
            try {
                HilosUpload::fromRow($missing);
                self::fail('A full row without ' . $field . ' was accepted.');
            } catch (InvalidFormatException $refusal) {
                self::assertStringContainsString($field, $refusal->getMessage());
            }
        }
    }

    public function testSnapshotUsesTheConnectionOrderAndRefusesStaleOrIncompleteRows(): void
    {
        $upload = $this->completeUpload();
        $other = $this->completeUpload('file-2');
        $snapshot = Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['file-2', self::UPLOAD_ID]);
        self::assertSame(['file-2', self::UPLOAD_ID], array_column($snapshot->toArray(), 'clientUploadId'));
        self::assertCount(0, Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, []));
        foreach (['other-connection', self::ACCEPT_KEY] as $acceptKey) {
            RtStaleness::mark(HilosUpload::RT_COLLECTION, [$other->getId()], microtime(true));
            try {
                Hilos::$rt->hilosUploads->snapshotForConnection($acceptKey, [self::UPLOAD_ID, 'file-2']);
                self::fail('A missing or stale upload was admitted.');
            } catch (InvalidStateException) {
            }
        }
        RtStaleness::reset();
        $row = $upload->toArray();
        foreach ([
            HilosUpload::phase => UploadPhase::READY->value, HilosUpload::tmpIndex => null, HilosUpload::contentHash => null,
        ] as $key => $value) {
            RtSnapshot::replace(HilosUpload::RT_COLLECTION, [$upload->getId() => array_replace($row, [$key => $value])]);
            try {
                Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, [self::UPLOAD_ID]);
                self::fail('An upload without a complete byte identity was admitted.');
            } catch (InvalidStateException) {
            }
        }
        RtSnapshot::replace(HilosUpload::RT_COLLECTION, [$upload->getId() => $row]);
        foreach ([[self::UPLOAD_ID, self::UPLOAD_ID], ['key' => self::UPLOAD_ID], [false], ['bad/id']] as $ids) {
            try {
                Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, $ids);
                self::fail('An invalid upload id list was admitted.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    /**
     * @param string $id Upload to receive
     * @return ViewHilosUpload Upload completed through its owner's actions
     */
    private function completeUpload(string $id = self::UPLOAD_ID): ViewHilosUpload
    {
        $upload = Hilos::$rt->hilosUploads->actions->open(self::ACCEPT_KEY, $id, 'unit', 7, 'file.txt', 'text/plain', 4, str_repeat('a', 32));
        $upload->actions->inspect(hash('sha256', 'file'), null);
        $upload->actions->complete();

        return $upload;
    }
}
