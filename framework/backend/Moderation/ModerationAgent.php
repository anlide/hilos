<?php

declare(strict_types=1);

namespace Hilos\Moderation;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\ModerationRules;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\DTO\MessageImage;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Exception\LLMException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosModerationCheck;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\WiringRefusal;

/**
 * ModerationAgent - the cluster's one moderator (HIL-1625).
 *
 * Takes the oldest check still waiting in the runtime queue ({@see HilosModerationCheck}), asks a
 * model about it with the rules of its kind, and sends the verdict to the agent the kind names as
 * {@see HilosSignalConstants::HILOS_MODERATION_VERDICT}; the router finds that agent by the kind.
 * Applying the verdict is the recipient's work: the moderator owns nothing and writes nothing - not
 * the queue, which each initiator writes for its own set, not the subject, not the rules.
 *
 * One check at a time, oldest first by (startedAt, row id), and only a check whose deadline has not
 * passed. The kind, its rules and the model profile are read afresh right before each check goes to
 * the model, so a changed rule or setting acts on the next check and never on the one in flight. A
 * check that cannot be asked - no model, no file, no rules, no person - or an answer that is not a
 * verdict ends as {@see ModerationReason::SERVICE_UNAVAILABLE}, which permits nothing. A check whose
 * row is removed or replaced while it is with the model is dropped without a verdict, and once a
 * verdict has gone out the moderator waits for its row to leave rather than answering it again.
 *
 * What it keeps in its own memory is the handle of the operation in flight and nothing another
 * process reads: the async client and the profile it was built from, the snapshot of the attempt in
 * hand, whether its verdict has gone out, and whether the queue changed since it last looked. The
 * queue and every deadline stay in the runtime collection of their owners
 * (docs/agents/framework-first.md).
 *
 * Not final for one reason: {@see self::createChatClient()} is where a test hands in a client double.
 */
class ModerationAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_MODERATOR;

    /**
     * The rules of each kind, the settings the model profiles are overridden from, and the person
     * whose name a prompt carries; the moderator writes none of them.
     *
     * @var list<string>
     */
    public const array READS_DB = [HilosDbContext::moderationRules, HilosDbContext::settings, HilosDbContext::users];

    /**
     * The checks every initiator opens, and the complete uploads an image check names. The
     * connections are not listed: the framework holds them in every process, under the project's
     * own key ({@see RtContext::declareProcessWideReads()}).
     *
     * @var list<string>
     */
    public const array READS_RT = [StateHilosModerationCheck::RT_COLLECTION, HilosUpload::RT_COLLECTION];

    /** Tokens a verdict needs; the answer is one short JSON object. */
    private const int VERDICT_MAX_TOKENS = 32;

    /** Profile the client in hand was built from, or null before the first check. */
    private ?LlmProfile $profile = null;

    /** Async client of {@see self::$profile}, or null before the first check. */
    private ?AsyncChatLLMInterface $client = null;

    /** Connection of the attempt in hand, or null while the moderator is free. */
    private ?string $acceptKey = null;

    /** Kind key of the attempt in hand, or null while the moderator is free. */
    private ?string $kindKey = null;

    /** Request id of the attempt in hand - the one its verdict answers - or null while the moderator is free. */
    private ?string $requestId = null;

    /** Moment the attempt in hand stops waiting, in epoch milliseconds. */
    private int $deadlineAt = 0;

    /** The kind as declared when the attempt in hand went to the model; null while no model is asked. */
    private ?ModerationKind $kind = null;

    /** Whether the verdict of the attempt in hand has gone out. */
    private bool $answered = false;

    /** Whether the queue may hold a check the moderator has not looked at since it changed. */
    private bool $queueChanged = true;

    /**
     * Drops the attempt in hand without a verdict.
     */
    public function onStop(): void
    {
        $this->forgetAttempt();
    }

    /**
     * Notes a created check.
     *
     * @param RtSyncCreatedSignalData $data Runtime sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When the checks cannot be read
     */
    public function onSignalRtSyncCreated(RtSyncCreatedSignalData $data, string $source, string $name): void
    {
        $this->noteQueueChange($data->collectionKey);
    }

    /**
     * Notes a changed check.
     *
     * @param RtSyncUpdatedSignalData $data Runtime sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When the checks cannot be read
     */
    public function onSignalRtSyncUpdated(RtSyncUpdatedSignalData $data, string $source, string $name): void
    {
        $this->noteQueueChange($data->collectionKey);
    }

    /**
     * Notes a removed check.
     *
     * @param RtSyncDeletedSignalData $data Runtime sync payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When the checks cannot be read
     */
    public function onSignalRtSyncDeleted(RtSyncDeletedSignalData $data, string $source, string $name): void
    {
        $this->noteQueueChange($data->collectionKey);
    }

    /**
     * Drives the attempt in hand, or starts the oldest waiting check once the queue has changed.
     *
     * Short by construction: an attempt in flight only pumps its async client, an answered one only
     * compares its deadline, and the queue is walked in memory, and only after it changed.
     *
     * @throws HilosException When the checks cannot be read or a verdict cannot be queued
     */
    public function onTick(): void
    {
        if ($this->acceptKey !== null) {
            $this->advanceAttempt();

            return;
        }
        if (!$this->queueChanged) {
            return;
        }
        $this->queueChanged = false;
        $oldest = $this->oldestWaitingCheck();
        if ($oldest !== null) {
            $this->start($oldest);
        }
    }

    /**
     * Builds the client of a profile.
     *
     * The one place a test replaces the provider with a double.
     *
     * @param LlmProfile $profile Effective profile of the next check
     * @return AsyncChatLLMInterface Async client of the profile
     * @throws LLMConfigurationException When an external profile carries no API key
     */
    protected function createChatClient(LlmProfile $profile): AsyncChatLLMInterface
    {
        return ClientFactory::createChatClientForProfile($profile);
    }

    /**
     * Wakes the queue walk on any change of the checks, and drops the attempt in hand when its row
     * left or now carries another request id.
     *
     * @param string $collectionKey Runtime collection that changed
     * @throws HilosException When the checks cannot be read
     */
    private function noteQueueChange(string $collectionKey): void
    {
        if ($collectionKey !== StateHilosModerationCheck::RT_COLLECTION) {
            return;
        }
        $this->queueChanged = true;
        if ($this->acceptKey !== null && !$this->isStillAsked()) {
            $this->forgetAttempt();
        }
    }

    /**
     * Pumps the attempt in flight and answers it once the model has; lets an answered one go at its deadline.
     *
     * @throws HilosException When the checks cannot be read or a verdict cannot be queued
     */
    private function advanceAttempt(): void
    {
        if ($this->answered) {
            // Its row would not be started again once its deadline passed, so there is nothing
            // left to wait for even if the initiator never removes it.
            if (TimeHelper::nowMs() >= $this->deadlineAt) {
                $this->forgetAttempt();
            }

            return;
        }
        $client = $this->client;
        $kind = $this->kind;
        if ($client === null || $kind === null) {
            // A start or an answer cut short by a throw: nothing is in flight to wait for.
            $this->forgetAttempt();

            return;
        }
        try {
            $client->tick(microtime(true) * TimeConstants::MS_PER_SECOND);
            if ($client->isBusy() || !$client->hasResult()) {
                return;
            }
            $decision = ModerationDecision::fromModelOutput($client->consumeResult(), $kind);
        } catch (LLMException|ValidationException $e) {
            $this->logAgentError("Moderation check {$this->kindKey} gave no verdict: {$e->getMessage()}");
            $decision = ModerationDecision::unavailable();
        }
        $this->answer($decision);
    }

    /**
     * Picks the check to ask about next: the oldest by (startedAt, row id) among those whose
     * deadline has not passed and whose connection still carries the person who asked.
     *
     * A check left out here is not lost: an expired one is finished by its owner's sweep, and one
     * whose connection closed or changed hands is removed by its owner without a verdict.
     *
     * @return ?HilosModerationCheck The check to start, or null when none is waiting
     * @throws HilosException When the checks cannot be read
     */
    private function oldestWaitingCheck(): ?HilosModerationCheck
    {
        $now = TimeHelper::nowMs();
        $connections = Hilos::$rt->connectionsSource();
        $oldest = null;
        foreach (Hilos::$rt->hilosModerationChecks as $check) {
            if ($check->deadlineAt <= $now) {
                continue;
            }
            if ($connections !== null && $connections->get($check->acceptKey)?->userId !== $check->userId) {
                continue;
            }
            if ($oldest === null
                || $check->startedAt < $oldest->startedAt
                || ($check->startedAt === $oldest->startedAt && strcmp($check->getId(), $oldest->getId()) < 0)
            ) {
                $oldest = $check;
            }
        }

        return $oldest;
    }

    /**
     * Takes a check in hand and sends it to the model, or answers it unavailable when it cannot be asked.
     *
     * @param HilosModerationCheck $check Check to start
     * @throws HilosException When the checks cannot be read or a verdict cannot be queued
     */
    private function start(HilosModerationCheck $check): void
    {
        $this->acceptKey = $check->acceptKey;
        $this->kindKey = $check->kind;
        $this->requestId = $check->requestId;
        $this->deadlineAt = $check->deadlineAt;
        try {
            $kind = Hilos::moderationKinds()[$check->kind]
                ?? throw new InvalidArgumentException("Unknown moderation kind: {$check->kind}");
            $messages = $this->messagesOf($check, $kind);
            $profile = $this->profileOf($kind->input);
            $this->clientOf($profile)->startGenerate($messages, new ChatGenerateOptions(
                model: $profile->model,
                timeoutSec: min($profile->timeoutSec, ($this->deadlineAt - TimeHelper::nowMs()) / TimeConstants::MS_PER_SECOND),
                maxTokens: self::VERDICT_MAX_TOKENS,
            ));
            $this->kind = $kind;
        } catch (WiringRefusal $refusal) {
            $this->logAgentError("Moderation check {$check->kind} cannot read what it needs: {$refusal->getMessage()}");
            $this->answer(ModerationDecision::unavailable());
        } catch (HilosException $e) {
            $this->logAgentError("Moderation check {$check->kind} cannot be asked: {$e->getMessage()}");
            $this->answer(ModerationDecision::unavailable());
        }
    }

    /**
     * Builds what the model is asked: the policy as the system message, the person's content as the user message.
     *
     * The user message opens with the person's id and current name, the context the stand's model
     * dictations are keyed by.
     *
     * @param HilosModerationCheck $check Check being started
     * @param ModerationKind $kind Its kind, as declared now
     * @return list<Message> System and user messages
     * @throws InvalidStateException When the kind has no rules, the person is gone, or the kind looks at text with files
     * @throws InvalidArgumentException When the project no longer publishes the kind
     * @throws FsException When the uploaded image cannot be read
     * @throws HilosException When the rules, the person or the upload cannot be read
     */
    private function messagesOf(HilosModerationCheck $check, ModerationKind $kind): array
    {
        $rules = Hilos::$db->moderationRules->effectiveForKind($kind->key);
        if ($rules === []) {
            throw new InvalidStateException("Moderation kind {$kind->key} has no rules to apply");
        }
        $person = Hilos::$db->users[$check->userId]
            ?? throw new InvalidStateException("Person {$check->userId} left before the check was asked");
        $context = ['User ID: ' . $check->userId, 'User: ' . $person->name];
        $policy = new Message(Message::ROLE_SYSTEM, self::policyOf($kind, $rules));

        return match ($kind->input) {
            ModerationInput::TEXT => [
                $policy,
                new Message(Message::ROLE_USER, implode("\n\n", [...$context, "Text:\n" . $check->text])),
            ],
            ModerationInput::IMAGE => [
                $policy,
                new Message(Message::ROLE_USER, implode("\n\n", $context), [$this->imageOf($check)]),
            ],
            ModerationInput::TEXT_WITH_FILES => throw new InvalidStateException(
                "Moderation kind {$kind->key} looks at text with files, which the moderator cannot ask about yet",
            ),
        };
    }

    /**
     * Writes the policy: what the check is, the two answers the model may give, and the rules in force.
     *
     * @param ModerationKind $kind Kind of the check
     * @param list<string> $rules Rules the kind applies now ({@see ModerationRules::effectiveForKind()})
     * @return string System message
     */
    private static function policyOf(ModerationKind $kind, array $rules): string
    {
        return implode("\n", [
            "Moderation of: {$kind->title}. JSON only.",
            sprintf('Allowed: {"%s":true,"%s":"%s"}', ModerationDecision::allow, ModerationDecision::reason, ModerationReason::OK),
            sprintf(
                'Refused: {"%s":false,"%s":"%s"}',
                ModerationDecision::allow,
                ModerationDecision::reason,
                implode('|', $kind->reasons),
            ),
            'Rules:',
            ...array_map(static fn (string $rule): string => "- {$rule}", $rules),
        ]);
    }

    /**
     * Reads the complete upload an image check names out of the cluster's temporary directory.
     *
     * The upload is found by its connection and id and the file by its temporary index, so no path
     * travels between processes.
     *
     * @param HilosModerationCheck $check Image check being started
     * @return MessageImage The image, with the type read from its content when there is one
     * @throws InvalidStateException When the check names no upload, the upload is gone or not complete, or files are
     *     unavailable in this process
     * @throws FsException When the temporary directory is not configured or the file cannot be read
     * @throws HilosException When the uploads cannot be read
     */
    private function imageOf(HilosModerationCheck $check): MessageImage
    {
        $clientUploadId = $check->clientUploadId
            ?? throw new InvalidStateException('An image check names no upload');
        $upload = Hilos::$rt->hilosUploads->find($check->acceptKey, $clientUploadId);
        if ($upload?->phase !== UploadPhase::COMPLETE || $upload->tmpIndex === null) {
            throw new InvalidStateException('The upload of the check is gone or no longer complete');
        }
        $fs = Hilos::$fs ?? throw new InvalidStateException('Temporary files are unavailable in this process');

        return new MessageImage(
            $upload->detectedMimeType ?? $upload->mimeType,
            base64_encode(FsPath::read($fs->getTmp()[$upload->tmpIndex]->getPath())),
        );
    }

    /**
     * Resolves the effective profile of the next check: the image profile for an image, the text
     * profile for everything else.
     *
     * @param ModerationInput $input What the next check looks at
     * @return LlmProfile Effective profile, with the moderation settings applied
     * @throws InvalidStateException When the LLM router is not initialized
     * @throws LLMConfigurationException When the profile cannot be resolved
     * @throws HilosException When an env value or a setting the profile reads cannot be read
     */
    private function profileOf(ModerationInput $input): LlmProfile
    {
        $router = Hilos::$llm ?? throw new InvalidStateException('The LLM router is not initialized');

        return $router->resolve($input === ModerationInput::IMAGE
            ? ModerationLlmConstants::PROFILE_IMAGE
            : ModerationLlmConstants::PROFILE_TEXT);
    }

    /**
     * Hands back the client of a profile, replacing the client in hand only when the profile it
     * was built from differs.
     *
     * A failed build leaves the check without a client: it is not asked of the old one.
     *
     * @param LlmProfile $profile Effective profile of the next check
     * @return AsyncChatLLMInterface Client for the next check
     * @throws LLMConfigurationException When an external profile carries no API key
     */
    private function clientOf(LlmProfile $profile): AsyncChatLLMInterface
    {
        if ($this->client !== null && $this->profile !== null && self::isSameProfile($profile, $this->profile)) {
            return $this->client;
        }
        $client = $this->createChatClient($profile);
        $this->client = $client;
        $this->profile = $profile;

        return $client;
    }

    /**
     * Compares two profiles field by field, which `==` on objects would not do strictly.
     *
     * @param LlmProfile $a One profile
     * @param LlmProfile $b The other
     * @return bool Whether a client of one serves the other unchanged
     */
    private static function isSameProfile(LlmProfile $a, LlmProfile $b): bool
    {
        return $a->key === $b->key
            && $a->provider === $b->provider
            && $a->url === $b->url
            && $a->model === $b->model
            && $a->apiKey === $b->apiKey
            && $a->timeoutSec === $b->timeoutSec
            && $a->placement === $b->placement;
    }

    /**
     * Sends the verdict of the attempt in hand, unless its row has left or been replaced meanwhile.
     *
     * @param ModerationDecision $decision Verdict to send
     * @throws HilosException When the checks cannot be read or the verdict cannot be queued
     */
    private function answer(ModerationDecision $decision): void
    {
        if ($this->acceptKey === null || $this->kindKey === null || $this->requestId === null || !$this->isStillAsked()) {
            $this->forgetAttempt();

            return;
        }
        $this->sendToAgent(
            HilosSignalConstants::HILOS_MODERATION_VERDICT,
            new ModerationVerdictSignalData($this->acceptKey, $this->kindKey, $this->requestId, $decision->allow, $decision->reason),
        );
        $this->kind = null;
        $this->answered = true;
    }

    /**
     * Whether the row of the attempt in hand is still there under the same request id.
     *
     * @return bool False when the moderator holds no attempt, or its row left or was replaced
     * @throws HilosException When the checks cannot be read
     */
    private function isStillAsked(): bool
    {
        return $this->acceptKey !== null
            && $this->kindKey !== null
            && Hilos::$rt->hilosModerationChecks->find($this->acceptKey, $this->kindKey)?->requestId === $this->requestId;
    }

    /**
     * Lets the attempt in hand go: the client is reset, and the queue is walked again on the next tick.
     */
    private function forgetAttempt(): void
    {
        $this->client?->reset();
        $this->acceptKey = null;
        $this->kindKey = null;
        $this->requestId = null;
        $this->deadlineAt = 0;
        $this->kind = null;
        $this->answered = false;
        $this->queueChanged = true;
    }
}
