<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Moderation;

use Closure;
use Hilos\API\Exception\AsyncHttpStatusException;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\Exception\LLMRequestException;
use Hilos\LLM\Exception\LLMResponseException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\Log\AgentLogStream;
use Hilos\Moderation\DTO\ModerationFailure;
use Hilos\Moderation\ModerationFailureLog;
use Hilos\Utils\Logger;
use Hilos\Utils\LogLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;
use WeakReference;

final class ModerationFailureLogTest extends TestCase
{
    private const string AGENT_ID = 'moderation:test';

    private string $logDirectory;

    /** @return iterable<string, array{string, bool}> Outcome and its actual stream */
    public static function outcomes(): iterable
    {
        yield 'malformed warning' => [ModerationFailure::MALFORMED_ANSWER, false];
        yield 'model error' => [ModerationFailure::MODEL_FAILURE, true];
        yield 'preparation error' => [ModerationFailure::CHECK_UNAVAILABLE, true];
    }

    /**
     * @param string $outcome Diagnostic outcome
     * @param bool $errorStream Whether the outcome belongs in the error twin
     */
    #[DataProvider('outcomes')]
    public function testOutcomesWriteTheirCompleteContractToTheAgentStream(string $outcome, bool $errorStream): void
    {
        $this->withLogs(function () use ($outcome, $errorStream): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            $writer->write($this->failure(outcome: $outcome), 1000.0);
            $writer->flushAll();

            $entries = $this->entries($errorStream);
            $this->assertCount(1, $entries);
            $this->assertSame([
                'event' => 'moderation_check_failure',
                'outcome' => $outcome,
                'stage' => ModerationFailure::STAGE_PARSE,
                'kind' => 'example_kind',
                'requestId' => 'request-1',
                'profile' => 'example_profile',
                'provider' => LlmProvider::EXTERNAL->value,
                'model' => 'example_model',
                'reason' => 'Invalid answer',
                'errorClass' => ValidationException::class,
                'causeClass' => null,
                'httpStatus' => null,
                'raw' => $outcome === ModerationFailure::MALFORMED_ANSWER ? 'raw answer' : null,
                'rawTruncated' => false,
                'summary' => false,
                'suppressed' => 0,
                'firstRequestId' => 'request-1',
                'lastRequestId' => 'request-1',
                'windowSec' => 60,
            ], $entries[0]);
            $this->assertSame([], $this->entries(!$errorStream));
            $line = file_get_contents(AgentLogStream::pathFor($this->logDirectory, self::AGENT_ID, $errorStream));
            $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2}T[^\]]+Z\] /', $line);
            if (!$errorStream) {
                $this->assertStringContainsString('[' . Logger::LEVEL_WARNING . '] Moderation check failure ', $line);
            }
        });
    }

    public function testSnapshotsRedactBeforeByteClippingAndEncodeInvalidUtfAndControlsOnOneLine(): void
    {
        $this->withLogs(function (): void {
            $key = 'known-private-key';
            $profile = $this->profile(model: "model\r\n\0|" . Logger::AGENT_LOG_MARKER, apiKey: $key);
            $failure = ModerationFailure::capture(
                ModerationFailure::MALFORMED_ANSWER,
                ModerationFailure::STAGE_PARSE,
                'example_kind',
                'request-safe',
                new ValidationException(str_repeat('x', 500) . $key . str_repeat('r', 1000)),
                profile: $profile,
                raw: "\xff\r\n\0|" . $key . str_repeat('z', 1000),
            );
            $this->assertLessThanOrEqual(512, strlen($failure->reason));
            $this->assertLessThanOrEqual(512, strlen($failure->raw));
            $this->assertStringNotContainsString($key, serialize($failure));
            $this->assertStringNotContainsString('known-privat', $failure->reason);
            $this->assertTrue($failure->rawTruncated);
            new ModerationFailureLog(self::AGENT_ID)->write($failure, 1000.0);

            $line = file_get_contents(AgentLogStream::pathFor($this->logDirectory, self::AGENT_ID, false));
            $this->assertSame(1, substr_count($line, "\n"));
            $this->assertStringNotContainsString("\r", $line);
            $this->assertStringNotContainsString("\0", $line);
            $this->assertStringNotContainsString($key, $line);
            $entry = $this->entries()[0];
            $this->assertStringContainsString("\u{fffd}", $entry['raw']);
            $this->assertSame($profile->model, $entry['model']);
            $this->assertTrue($entry['rawTruncated']);
        });
    }

    public function testTheSnapshotRetainsOnlyScalarsAndDoesNotKeepTheExceptionOrProfileAlive(): void
    {
        $error = new ValidationException('Invalid answer');
        $profile = $this->profile(apiKey: 'do-not-retain');
        $errorReference = WeakReference::create($error);
        $profileReference = WeakReference::create($profile);
        $failure = ModerationFailure::capture(
            ModerationFailure::MALFORMED_ANSWER,
            ModerationFailure::STAGE_PARSE,
            'example_kind',
            'request-1',
            $error,
            profile: $profile,
            raw: str_repeat('x', 100000),
        );
        unset($error, $profile);

        $this->assertNull($errorReference->get());
        $this->assertNull($profileReference->get());
        $this->assertTrue(new ReflectionClass($failure)->isReadOnly());
        foreach ((array)$failure as $value) {
            $this->assertTrue(is_scalar($value) || $value === null);
        }
        $this->assertLessThan(5000, strlen(serialize($failure)));
    }

    public function testMissingMalformedInputIsAnInvariantFailure(): void
    {
        $this->expectException(LogicException::class);
        ModerationFailure::capture(
            ModerationFailure::MALFORMED_ANSWER,
            ModerationFailure::STAGE_PARSE,
            'example_kind',
            'request-1',
            new ValidationException('Invalid answer'),
        );
    }

    public function testByteBudgetsIncludeUtf8ReplacementAndMultibyteCharacters(): void
    {
        $this->withLogs(function (): void {
            $failure = $this->failure(message: str_repeat("\xff", 400), raw: str_repeat("\xff", 400));
            $writer = new ModerationFailureLog(self::AGENT_ID);
            $writer->write($failure, 1000.0);
            $writer->write($this->failure(requestId: 'unicode', message: str_repeat('я', 300), raw: str_repeat('я', 300)), 1000.0);
            foreach ($this->entries() as $entry) {
                $this->assertLessThanOrEqual(512, strlen($entry['raw']));
                $this->assertLessThanOrEqual(512, strlen($entry['reason']));
                $this->assertTrue($entry['rawTruncated']);
            }
            $this->assertSame(str_repeat("\u{fffd}", 170), $failure->raw);
        });
    }

    public function testIdentifiersAreBoundedWithoutMergingDistinctLongIdentities(): void
    {
        $this->withLogs(function (): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            foreach (['a', 'b'] as $suffix) {
                for ($attempt = 0; $attempt < 4; $attempt++) {
                    $writer->write($this->failure(
                        kind: str_repeat('k', 10000),
                        requestId: str_repeat('r', 10000),
                        profile: $this->profile(key: str_repeat('p', 10000), model: str_repeat('m', 10000) . $suffix),
                    ), 1000.0);
                }
            }
            $writer->flushAll();
            $this->assertCount(8, $this->entries());
            foreach ($this->entries() as $entry) {
                foreach (['kind', 'requestId', 'profile', 'model', 'firstRequestId', 'lastRequestId'] as $field) {
                    $this->assertLessThanOrEqual(512, strlen($entry[$field]));
                }
            }
        });
    }

    public function testOneHundredAndThreeAttemptsWriteThreeDetailsAndOneSummaryOfOneHundred(): void
    {
        $this->withLogs(function (): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            for ($attempt = 1; $attempt <= 103; $attempt++) {
                $writer->write($this->failure(requestId: 'request-' . $attempt, message: 'Invalid answer ' . $attempt), 1000.0);
            }
            $this->assertCount(3, $this->entries());
            $writer->flushAll();

            $entries = $this->entries();
            $this->assertCount(4, $entries);
            $this->assertSame([false, false, false, true], array_column($entries, 'summary'));
            $this->assertSame([0, 0, 0, 100], array_column($entries, 'suppressed'));
            $this->assertSame('request-1', $entries[3]['firstRequestId']);
            $this->assertSame('request-103', $entries[3]['lastRequestId']);
            $this->assertSame('request-103', $entries[3]['requestId']);
            $this->assertSame('Invalid answer 103', $entries[3]['reason']);
            $this->assertSame('raw answer', $entries[3]['raw']);
        });
    }

    public function testDifferentDimensionsAndExceptionSourcesDoNotSilenceEachOther(): void
    {
        $this->withLogs(function (): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            $this->flood($writer, 4);
            $alternatives = [
                $this->failure(kind: 'another_kind'),
                $this->failure(profile: $this->profile(model: 'another_model')),
                $this->failure(profile: $this->profile(key: 'another_profile')),
                $this->failure(profile: $this->profile(provider: LlmProvider::LOCAL)),
                $this->failure(stage: ModerationFailure::STAGE_ANSWER),
                $this->failure(error: new ValidationException('Another throw site')),
                $this->failure(error: new InvalidFormatException('Different error type')),
                $this->failure(error: $this->wrapped(new RuntimeException('cause-one'))),
                $this->failure(error: $this->wrapped(new InvalidFormatException('cause-two'))),
                $this->failure(error: $this->wrapped(new AsyncHttpStatusException(HttpConstants::HTTP_BAD_REQUEST, 'private body'))),
                $this->failure(error: $this->wrapped(new AsyncHttpStatusException(HttpConstants::HTTP_INTERNAL_ERROR, 'private body'))),
            ];
            foreach ($alternatives as $failure) {
                $writer->write($failure, 1000.0);
            }
            $this->assertCount(3 + count($alternatives), $this->entries());
            $writer->write($this->failure(outcome: ModerationFailure::MODEL_FAILURE), 1000.0);
            $writer->write($this->failure(outcome: ModerationFailure::CHECK_UNAVAILABLE), 1000.0);
            $this->assertCount(2, $this->entries(true));
        });
    }

    public function testIdleExpiryReleasesMetadataAndIsIdempotent(): void
    {
        $this->withLogs(function (): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            $this->flood($writer, 5);
            $writer->flushExpired(1059.9);
            $this->assertCount(3, $this->entries());
            $writer->flushExpired(1060.0);
            $writer->flushExpired(1200.0);
            $writer->flushAll();

            $this->assertSame([0, 0, 0, 2], array_column($this->entries(), 'suppressed'));
            $this->assertSame([], new ReflectionClass($writer)->getProperty('groups')->getValue($writer));
        });
    }

    public function testTheBoundaryClosesTheOldSnapshotBeforeTheNewAttemptOpensItsWindow(): void
    {
        $this->withLogs(function (): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            $this->flood($writer, 4);
            $writer->write($this->failure(requestId: 'new-window', message: 'new message'), 1060.0);
            $entries = $this->entries();
            $this->assertCount(5, $entries);
            $this->assertTrue($entries[3]['summary']);
            $this->assertSame('request-4', $entries[3]['requestId']);
            $this->assertSame('Invalid answer 4', $entries[3]['reason']);
            $this->assertFalse($entries[4]['summary']);
            $this->assertSame('new-window', $entries[4]['firstRequestId']);
            $this->assertSame('new-window', $entries[4]['lastRequestId']);
        });
    }

    public function testStopClosesOnceAndRestoresTheBurstWithoutEmptySummaries(): void
    {
        $this->withLogs(function (): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            $this->flood($writer, 4);
            $writer->flushAll();
            $writer->flushAll();
            $this->flood($writer, 3);
            $writer->flushAll();
            $this->assertSame([0, 0, 0, 1, 0, 0, 0], array_column($this->entries(), 'suppressed'));
        });
    }

    public function testCapacityEvictsTheOldestGroupWithItsSummaryAndKeepsOtherCounts(): void
    {
        $this->withLogs(function (): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            for ($group = 0; $group < 128; $group++) {
                for ($attempt = 1; $attempt <= 4; $attempt++) {
                    $writer->write($this->failure(kind: 'kind-' . $group, requestId: 'request-' . $attempt), 1000.0);
                }
            }
            // Updating the first group must not turn eviction into least-recently-used order.
            $writer->write($this->failure(kind: 'kind-0', requestId: 'latest-oldest'), 1001.0);
            $writer->write($this->failure(kind: 'overflow'), 1001.0);
            $entries = $this->entries();
            $summary = $entries[384];
            $this->assertTrue($summary['summary']);
            $this->assertSame('kind-0', $summary['kind']);
            $this->assertSame(2, $summary['suppressed']);
            $this->assertSame('latest-oldest', $summary['lastRequestId']);
            $this->assertCount(128, new ReflectionClass($writer)->getProperty('groups')->getValue($writer));
            $writer->write($this->failure(kind: 'kind-1'), 1001.0);
            $writer->flushAll();
            $summaries = array_values(array_filter($this->entries(), static fn(array $entry): bool => $entry['summary']));
            $this->assertCount(128, $summaries);
            $this->assertSame('kind-1', $summaries[1]['kind']);
            $this->assertSame(2, $summaries[1]['suppressed']);
            $this->assertSame([], new ReflectionClass($writer)->getProperty('groups')->getValue($writer));
        });
    }

    public function testSummaryKeepsTheLastBoundedRawSampleAndTheFirstRequestId(): void
    {
        $this->withLogs(function (): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            for ($attempt = 1; $attempt <= 4; $attempt++) {
                $writer->write($this->failure(requestId: 'request-' . $attempt, raw: str_repeat((string)$attempt, 10000)), 1000.0);
            }
            $writer->flushAll();
            $summary = $this->entries()[3];
            $this->assertSame(str_repeat('4', 512), $summary['raw']);
            $this->assertTrue($summary['rawTruncated']);
            $this->assertSame('request-1', $summary['firstRequestId']);
            $this->assertSame('request-4', $summary['lastRequestId']);
        });
    }

    public function testUnresolvedProfileAndOwnDeadlineDoNotBorrowAnEarlierEffectiveProfile(): void
    {
        $this->withLogs(function (): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            $writer->write($this->failure(), 1000.0);
            foreach (['requested_profile', null] as $requestedProfile) {
                $writer->write(ModerationFailure::capture(
                    ModerationFailure::CHECK_UNAVAILABLE,
                    ModerationFailure::STAGE_PREPARE,
                    'unresolved_kind',
                    'unresolved-request',
                    new ValidationException('Cannot prepare check'),
                    requestedProfile: $requestedProfile,
                ), 1000.0);
            }
            $writer->write(ModerationFailure::capture(
                ModerationFailure::MODEL_FAILURE,
                ModerationFailure::STAGE_POLL,
                'example_kind',
                'deadline-request',
                null,
                profile: $this->profile(),
            ), 1000.0);
            $entries = $this->entries(true);
            $this->assertSame(['requested_profile', null], array_column(array_slice($entries, 0, 2), 'profile'));
            foreach (array_slice($entries, 0, 2) as $entry) {
                $this->assertNull($entry['provider']);
                $this->assertNull($entry['model']);
            }
            $this->assertSame('deadline_exceeded', $entries[2]['reason']);
            $this->assertNull($entries[2]['errorClass']);
            $this->assertNull($entries[2]['causeClass']);
        });
    }

    public function testHttpStatusAndInnermostCauseAreStructuredWithoutBodyOrPreviousMessages(): void
    {
        $this->withLogs(function (): void {
            $http = new AsyncHttpStatusException(HttpConstants::HTTP_INTERNAL_ERROR, 'private-http-body');
            $error = new LLMRequestException('Provider unavailable', new RuntimeException('private-middle-message', previous: $http));
            $writer = new ModerationFailureLog(self::AGENT_ID);
            $writer->write($this->failure(outcome: ModerationFailure::MODEL_FAILURE, error: $error), 1000.0);
            $entry = $this->entries(true)[0];
            $this->assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $entry['httpStatus']);
            $this->assertSame(LLMRequestException::class, $entry['errorClass']);
            $this->assertSame(AsyncHttpStatusException::class, $entry['causeClass']);
            $this->assertSame('Provider unavailable', $entry['reason']);
            $this->assertNull($entry['raw']);
            $this->assertFalse($entry['rawTruncated']);
            $line = file_get_contents(AgentLogStream::pathFor($this->logDirectory, self::AGENT_ID, true));
            $this->assertStringNotContainsString('private-http-body', $line);
            $this->assertStringNotContainsString('private-middle-message', $line);
            $this->assertStringNotContainsString('trace', $line);
        });
    }

    public function testProviderEnvelopeIsRemovedFromTheDiagnosticReason(): void
    {
        $this->withLogs(function (): void {
            $writer = new ModerationFailureLog(self::AGENT_ID);
            foreach ([LLMApiConstants::LOG_OPENAI_RESPONSE_INVALID, LLMApiConstants::LOG_OLLAMA_RESPONSE_INVALID] as $prefix) {
                $writer->write($this->failure(
                    outcome: ModerationFailure::MODEL_FAILURE,
                    error: new LLMResponseException($prefix . '{"private-envelope":"private-prompt"}'),
                ), 1000.0);
            }
            $entries = $this->entries(true);
            $this->assertCount(2, $entries);
            foreach ($entries as $entry) {
                $this->assertStringNotContainsString('private-envelope', $entry['reason']);
                $this->assertStringNotContainsString('private-prompt', $entry['reason']);
                $this->assertStringContainsString('invalid format', $entry['reason']);
                $this->assertNull($entry['raw']);
            }
        });
    }

    public function testTheLoggerWriteLevelAlsoFiltersDetailsAndSummaries(): void
    {
        $this->withLogs(function (): void {
            Logger::setWriteLevel(LogLevel::Error);
            $writer = new ModerationFailureLog(self::AGENT_ID);
            $this->flood($writer, 4);
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $writer->write($this->failure(outcome: ModerationFailure::MODEL_FAILURE), 1000.0);
            }
            $writer->flushAll();
            $this->assertSame([], $this->entries());
            $this->assertSame([0, 0, 0, 1], array_column($this->entries(true), 'suppressed'));
        });
    }

    public function testWriterInstancesHaveIndependentBurstAllowances(): void
    {
        $this->withLogs(function (): void {
            $first = new ModerationFailureLog(self::AGENT_ID);
            $second = new ModerationFailureLog('second-moderator');
            $this->flood($first, 4);
            $this->flood($second, 4);
            $first->flushAll();
            $second->flushAll();
            $this->assertCount(4, $this->entries());
            $path = AgentLogStream::pathFor($this->logDirectory, 'second-moderator', false);
            $this->assertSame(4, substr_count(file_get_contents($path), "\n"));
        });
    }

    /**
     * @param string $key Profile key
     * @param string $model Effective model
     * @param LlmProvider $provider Explicit provider independent of credentials
     * @param ?string $apiKey Known credential to redact
     * @return LlmProfile Example effective profile
     */
    private function profile(
        string $key = 'example_profile',
        string $model = 'example_model',
        LlmProvider $provider = LlmProvider::EXTERNAL,
        ?string $apiKey = null,
    ): LlmProfile {
        return new LlmProfile($key, $provider, 'https://provider.invalid/private-endpoint', $model, $apiKey, 30.0);
    }

    /**
     * @param string $requestId Correlation id
     * @param string $message Variable diagnostic text from one stable source
     * @param string $outcome Diagnostic outcome
     * @param string $kind Moderation kind
     * @param ?LlmProfile $profile Effective profile, or the example one
     * @param ?Throwable $error Failure, or a validation error at the shared source
     * @param string $stage Attempt stage
     * @param string $raw Parser input
     * @return ModerationFailure Bounded immutable snapshot
     */
    private function failure(
        string $requestId = 'request-1',
        string $message = 'Invalid answer',
        string $outcome = ModerationFailure::MALFORMED_ANSWER,
        string $kind = 'example_kind',
        ?LlmProfile $profile = null,
        ?Throwable $error = null,
        string $stage = ModerationFailure::STAGE_PARSE,
        string $raw = 'raw answer',
    ): ModerationFailure {
        return ModerationFailure::capture(
            $outcome,
            $stage,
            $kind,
            $requestId,
            $error ?? new ValidationException($message),
            profile: $profile ?? $this->profile(),
            raw: $raw,
        );
    }

    /**
     * @param Throwable $cause Innermost failure
     * @return LLMRequestException One wrapper source for different causes
     */
    private function wrapped(Throwable $cause): LLMRequestException
    {
        return new LLMRequestException('Wrapped transport failure', $cause);
    }

    /**
     * @param ModerationFailureLog $writer Writer under test
     * @param int $count Attempts in one window
     */
    private function flood(ModerationFailureLog $writer, int $count): void
    {
        for ($attempt = 1; $attempt <= $count; $attempt++) {
            $writer->write($this->failure(requestId: 'request-' . $attempt, message: 'Invalid answer ' . $attempt), 1000.0);
        }
    }

    /**
     * @param bool $errorStream Whether to read the agent's error twin
     * @return list<array<string, mixed>> JSON records from the actual agent stream
     */
    private function entries(bool $errorStream = false): array
    {
        $path = AgentLogStream::pathFor($this->logDirectory, self::AGENT_ID, $errorStream);
        if (!is_file($path)) {
            return [];
        }
        $entries = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $offset = strpos($line, 'Moderation check failure ');
            $this->assertNotFalse($offset);
            $entries[] = json_decode(substr($line, $offset + strlen('Moderation check failure ')), true, flags: JSON_THROW_ON_ERROR);
        }

        return $entries;
    }

    /**
     * @param Closure $check Scenario using real agent log files
     */
    private function withLogs(Closure $check): void
    {
        $logger = new ReflectionClass(Logger::class);
        $previousMain = $logger->getProperty('logFile')->getValue();
        $previousError = $logger->getProperty('errorLogFile')->getValue();
        $previousLevel = Logger::writeLevel();
        $this->logDirectory = sys_get_temp_dir() . '/hilos-moderation-log-' . bin2hex(random_bytes(8));
        mkdir($this->logDirectory);
        try {
            Logger::setLogFile($this->logDirectory . '/daemon.log');
            Logger::setErrorLogFile($this->logDirectory . '/daemon.error.log');
            Logger::setWriteLevel(LogLevel::Debug);
            $check();
        } finally {
            $logger->getProperty('logFile')->setValue(null, $previousMain);
            $logger->getProperty('errorLogFile')->setValue(null, $previousError);
            Logger::setWriteLevel($previousLevel);
            foreach (glob($this->logDirectory . '/*') as $path) {
                unlink($path);
            }
            rmdir($this->logDirectory);
        }
    }
}
