<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use Hilos\Constants\HttpConstants;

/**
 * ModelRoutes - the local model the stand pretends to be (HIL-925).
 *
 * The provider half answers what framework/backend/LLM/Local/Chat/AsyncOllamaChatProvider calls:
 * a completion over POST /api/generate, under a `/model` prefix. The product reads one field of the
 * answer, `response`, and that field carries exactly the text a spec dictated through the test half
 * before the product asked. Nothing else decides it - no dictionary of prompts and answers kept in
 * here, and no randomness: a model whose answer a spec knows in advance is the whole point, because
 * it opens every scenario that turns on what the model said.
 *
 * The kind of resident is the interlocutor: nothing it is told is delivered to a person, so there is
 * no mail domain, no forward to Mailpit and no way to read what arrived. A spec dictates the answer
 * and checks what the product did with it.
 *
 * What is dictated is the raw TEXT of the answer, not a parsed verdict. Parsing an allow/deny out of
 * that text is the business of whichever demo asked, and knowing it here would make the house the
 * property of one demo.
 *
 * The call is keyed by a SUBSTRING of its prompt, which is the one departure from "the key is a value
 * of the call". No field of a model call carries a value the spec knows in advance: the product builds
 * the prompt from its own template, and the model name is the same for every call. What the spec does
 * know is the string it put into the conversation itself - the text of a message, a new name - and the
 * prompt carries that string verbatim, so the resident looks for it there.
 *
 * A call nobody dictated an answer for is refused with a 503, not answered with a permissive default:
 * a model that says "yes" to anything is the in-process stub this resident exists to replace. For the
 * product the refusal reads as "the model is not answering right now", so that scenario comes for free.
 *
 * What it deliberately does not model: the token counters and timings of the real envelope, which the
 * product never reads and a modeled value would invite someone to rely on; and authorization, which a
 * local model does not have, so a token checked here would check what production does not have either.
 * How the answer leaves - a status, a delay, a cut, a hold - is the house's levers, and they work on
 * the provider route of this resident without anything of its own (HIL-922).
 */
final class ModelRoutes implements GatewayResident
{
    /** Channel name, which is also the prefix every route of this resident lives under. */
    public const string CHANNEL = 'model';

    /** Route the product asks the model at: the completion endpoint under the channel prefix. */
    private const string PATH_GENERATE = '/model/api/generate';

    /** Route a spec dictates an answer at. */
    private const string PATH_TEST_ANSWER = '/model/test/answer';

    /** Request field of a model call carrying the prompt the key is looked for in. */
    private const string FIELD_PROMPT = 'prompt';

    /** Request field of a model call naming the model, which the envelope carries back. */
    private const string FIELD_MODEL = 'model';

    /** Dictation field carrying the string the answer is keyed by. */
    private const string FIELD_KEY = 'key';

    /** Dictation field carrying the text the model answers with. */
    private const string FIELD_RESPONSE = 'response';

    /** Every field a dictation may carry. */
    private const array ANSWER_FIELDS = [self::FIELD_KEY, self::FIELD_RESPONSE];

    /** Refusal a call gets when no spec dictated its answer. */
    private const string ERROR_ANSWER_NOT_DICTATED = 'ANSWER_NOT_DICTATED';

    /** Reason the envelope names for an answer that ended on its own. */
    private const string DONE_REASON_STOP = 'stop';

    /** Format of the moment the envelope says the answer was made, ISO 8601 in UTC. */
    private const string CREATED_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Registers the channel's provider and test routes on one connection.
     *
     * The provider route is keyed by the announced key its prompt contains. A prompt that contains
     * none is keyed by the empty string, which the house's contract asks for and which no declaration
     * can carry, so nothing is found for it.
     *
     * @param GatewayRoutes $routes Routes of the connection being accepted
     */
    public function register(GatewayRoutes $routes): void
    {
        // Provider side: what framework/backend/LLM/Local/Chat/AsyncOllamaChatProvider calls.
        $routes->provider(
            HttpConstants::METHOD_POST,
            self::PATH_GENERATE,
            $this->generate(...),
            static fn(array $fields): string => self::announcedKey($fields) ?? '',
        );

        // Test side: what the model says next, which no spec can arrange any other way.
        $routes->test(HttpConstants::METHOD_POST, self::PATH_TEST_ANSWER, $this->testAnswer(...));
    }

    /**
     * The key a model call was dictated under, found in its prompt.
     *
     * @param array<string, mixed> $fields Request fields of the call
     * @return ?string First announced key the prompt contains, null when it contains none or is not a string
     */
    private static function announcedKey(array $fields): ?string
    {
        $prompt = $fields[self::FIELD_PROMPT] ?? null;

        return is_string($prompt) ? Store::keyAnnouncedIn(self::PATH_GENERATE, $prompt) : null;
    }

    /**
     * Answers one model call with the text dictated next for its key, spending that dictation.
     *
     * A trap worth knowing, and not a defect: the house works the key out BEFORE it takes the
     * behavior declared for the call ({@see GatewayRoutes::provider()}), and this handler works it
     * out again AFTER. A key announced only by a behavior - a spec that dictated a delay but no text -
     * is gone from the store by the time this runs, so the call gets the 503 with that delay. A delay
     * with no dictated answer is an undictated call.
     *
     * @param array<string, mixed> $fields Request fields
     * @return array<string, mixed> Completion envelope, or a 503 when no answer was dictated
     */
    private function generate(array $fields): array
    {
        $key = self::announcedKey($fields);
        $answer = $key === null ? null : Store::takeAnswer($key);
        if ($answer === null) {
            return StandGatewayTlsServer::json(
                ['ok' => false, 'error' => self::ERROR_ANSWER_NOT_DICTATED],
                HttpConstants::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return [
            'model' => $fields[self::FIELD_MODEL] ?? null,
            'created_at' => gmdate(self::CREATED_AT_FORMAT),
            'response' => $answer,
            'done' => true,
            'done_reason' => self::DONE_REASON_STOP,
        ];
    }

    /**
     * Test route: dictate the text the model answers with on the next call whose prompt carries a key.
     *
     * The dictation is checked in the order its contract lists - an unknown field, the key, then the
     * response - and a refusal is a 400 with the code, so the spec helper fails where the dictation
     * was made rather than later on a model that refused to answer. An empty response is accepted:
     * a model that answered with nothing is a scenario of the product.
     *
     * @param array<string, mixed> $fields Request fields
     * @return array<string, mixed> Acknowledgement, or a 400 naming the refusal
     */
    private function testAnswer(array $fields): array
    {
        try {
            if (array_diff(array_keys($fields), self::ANSWER_FIELDS) !== []) {
                throw new InvalidModelAnswerException(InvalidModelAnswerException::FIELD_UNKNOWN);
            }

            $key = $fields[self::FIELD_KEY] ?? null;
            if (!is_string($key) || $key === '') {
                throw new InvalidModelAnswerException(InvalidModelAnswerException::KEY_REQUIRED);
            }

            $response = $fields[self::FIELD_RESPONSE] ?? null;
            if (!is_string($response)) {
                throw new InvalidModelAnswerException(InvalidModelAnswerException::RESPONSE_REQUIRED);
            }

            Store::pushAnswer($key, $response);
        } catch (InvalidModelAnswerException $refusal) {
            return StandGatewayTlsServer::json(['ok' => false, 'error' => $refusal->error], HttpConstants::HTTP_BAD_REQUEST);
        }

        return ['ok' => true];
    }
}
